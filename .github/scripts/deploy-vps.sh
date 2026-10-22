#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

deploy_commit=${1:-}
if [[ ! "$deploy_commit" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Le SHA du commit testé est obligatoire." >&2
    exit 1
fi
if [[ $EUID -ne 0 ]]; then
    echo "Ce déploiement correspond à l'installation root décrite dans DEPLOIEMENT_VPS.md." >&2
    exit 1
fi

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export GIT_TERMINAL_PROMPT=0
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

exec 9>/run/lock/titan-deploy.lock
flock -w 60 9

cd /var/www/titan
test -d .git
test -f .env
test -s /var/lib/titan/database.sqlite
test ! -e storage/framework/down
test ! -e public/hot
grep -qx 'APP_ENV=production' .env
grep -qx 'DB_CONNECTION=sqlite' .env
grep -qx 'DB_DATABASE=/var/lib/titan/database.sqlite' .env
php_binary=$(command -v php8.5)
composer_binary=$(command -v composer)
command -v sqlite3 >/dev/null
command -v curl >/dev/null
systemctl is-active --quiet php8.5-fpm

if [[ -s /root/.nvm/nvm.sh ]]; then
    export NVM_DIR=/root/.nvm
    # shellcheck source=/dev/null
    . "$NVM_DIR/nvm.sh"
    nvm use 24
fi
node -e 'if (Number(process.versions.node.split(".")[0]) !== 24) process.exit(1)'
command -v npm >/dev/null

test "$(git branch --show-current)" = main
git diff --quiet
git diff --cached --quiet
git fetch --no-tags origin main
git cat-file -e "$deploy_commit^{commit}"
git merge-base --is-ancestor "$deploy_commit" origin/main
git merge-base --is-ancestor HEAD "$deploy_commit"
if [[ "$(git rev-parse origin/main)" != "$deploy_commit" ]]; then
    echo "Un push plus récent existe sur main. Déploiement ignoré ; son workflow prendra le relais."
    exit 0
fi

maintenance_started=false
backup_directory=
deployment_exit() {
    local exit_status=$?
    trap - EXIT
    if (( exit_status != 0 )) && [[ "$maintenance_started" = true ]]; then
        runuser -u www-data -- "$php_binary" artisan down --retry=60 || true
        echo "Déploiement échoué. Le site reste en maintenance. Sauvegarde : $backup_directory" >&2
        echo "Vérifiez les journaux et les migrations avant de restaurer ou relancer." >&2
    fi
    exit "$exit_status"
}
trap deployment_exit EXIT

runuser -u www-data -- "$php_binary" artisan down --retry=60
maintenance_started=true
# Laisser finir les requêtes déjà engagées avant la sauvegarde.
sleep 5
backup_directory="/root/backups/titan/$(date -u +%Y%m%d-%H%M%S)-${deploy_commit:0:12}"
install -d -m 700 "$backup_directory"
sqlite3 /var/lib/titan/database.sqlite ".backup '$backup_directory/database.sqlite'"
install -m 600 .env "$backup_directory/env"
git rev-parse HEAD > "$backup_directory/previous-commit"
tar -czf "$backup_directory/storage.tar.gz" storage/app

git merge --ff-only "$deploy_commit"
"$php_binary" "$composer_binary" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$php_binary" "$composer_binary" check-platform-reqs --no-dev
npm ci --include=dev
npm run build
test -f public/build/manifest.json

chown -R root:www-data /var/www/titan
chmod -R u=rwX,g=rX,o= /var/www/titan
chmod 640 .env
chmod 700 .git
chown -R www-data:www-data storage bootstrap/cache /var/lib/titan

runuser -u www-data -- "$php_binary" artisan config:clear --no-interaction
runuser -u www-data -- "$php_binary" artisan migrate --force --no-interaction
runuser -u www-data -- "$php_binary" artisan optimize --no-interaction
systemctl reload php8.5-fpm
runuser -u www-data -- "$php_binary" artisan up

for endpoint in up login; do
    http_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
        --connect-timeout 10 --max-time 30 \
        --resolve titan.newtechnologyhub.org:443:127.0.0.1 \
        "https://titan.newtechnologyhub.org/$endpoint")
    test "$http_status" = 200
done

maintenance_started=false
echo "Commit $deploy_commit déployé. HTTPS /up et /login : OK."
echo "Sauvegarde : $backup_directory"
