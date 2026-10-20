# Déployer Titan Transfer sur le VPS

- Destination : **https://titan.newtechnologyhub.org**
- Adresse du VPS : **102.223.209.43**
- Connexion : **root**
- Dépôt : **https://github.com/AshDest/titan_transfer.git**
- Branche : **main**
- Système confirmé : **Debian 12 (bookworm)**
- PHP CLI confirmé : **8.5.10**

Cette procédure réalise une première installation avec une **base SQLite vide**, Nginx et PHP 8.5. Elle ne transfère pas les clients, opérations, comptes ni mots de passe de votre poste local.

Cette procédure tient compte de **Debian 12 et de PHP CLI 8.5.10 déjà installé**, d’après votre sortie de terminal. Elle ne suppose pas que Nginx, PHP-FPM, Composer ou Node sont présents : leurs résultats n’ont pas été fournis. Le nom de machine `panel` ne suffit pas à identifier un panneau d’hébergement.

Si un panneau gère les sites, PHP ou les certificats, créez le site Titan depuis ce panneau avec PHP 8.5 et la racine `/var/www/titan/public` (ou le chemin attribué par le panneau). Adaptez alors les chemins et l’utilisateur du site ; les étapes Nginx/Certbot et les permissions `www-data` ci-dessous concernent une installation Debian gérée directement. Ne remplacez pas la configuration des autres sites.

Exécutez les étapes dans l’ordre et arrêtez-vous si une commande échoue. Les blocs indiquent où lancer les commandes.

## 1. Vérifier l’accès et le serveur

**Sur votre ordinateur :**

```bash
ssh -o IdentitiesOnly=yes root@102.223.209.43
```

Avec une clé particulière, ajoutez `-i /chemin/vers/la_cle_privee`. Si vous utilisez uniquement un mot de passe :

```bash
ssh -o PubkeyAuthentication=no -o PreferredAuthentications=password root@102.223.209.43
```

Saisissez le mot de passe à l’invite, sans le mettre dans la commande.

Votre terminal affiche `newtech@panel`. Si vous êtes connecté avec `newtech`, passez en root avant les commandes serveur :

```bash
sudo -i
whoami
```

`whoami` doit afficher `root`. Si `newtech` n’a pas les droits sudo, utilisez l’accès root ou la console fournie par l’hébergeur.

**Sur le VPS, en root :**

```bash
cat /etc/os-release
php -v
command -v php
command -v php8.5
php --ini
php -m
command -v composer
command -v node
command -v npm
command -v nginx
ls -l /usr/sbin/nginx
systemctl list-units --all --type=service | grep -E 'nginx|apache|php.*fpm|plesk|hestia'
ls -l /run/php/
ss -ltnp
apt-cache policy php8.5-cli php8.5-fpm
```

Certaines commandes peuvent ne rien trouver si le composant est absent. `command -v nginx` lancé avec `newtech` peut aussi ne rien afficher parce que `/usr/sbin` n’est pas dans son PATH. PHP en ligne de commande **ne confirme pas la présence de PHP-FPM**, nécessaire pour servir Laravel via Nginx.

Vérifiez les programmes qui utilisent déjà les ports 80 et 443. Si Nginx est présent, examinez ses sites avec `nginx -T` avant d’ajouter Titan. Si un autre serveur web ou un panneau occupe ces ports, utilisez sa configuration existante.

Dans la zone DNS de `newtechnologyhub.org`, l’enregistrement attendu est :

| Type | Nom | Valeur |
| --- | --- | --- |
| A | titan | 102.223.209.43 |

Le pointage a été vérifié le 20 octobre 2026. Si Cloudflare est utilisé, laissez temporairement cet enregistrement en **DNS uniquement** pendant l’installation. Un éventuel enregistrement AAAA doit également pointer vers ce VPS, sinon retirez cet AAAA incorrect.

Autorisez les ports TCP **80 et 443** dans le pare-feu de l’hébergeur et celui du serveur. Conservez votre accès SSH. Si UFW est déjà actif :

```bash
ufw status
ufw allow 80/tcp
ufw allow 443/tcp
```

## 2. Compléter uniquement les outils manquants

**Sur le VPS Debian 12, en root :**

Le `composer.lock` actuel exige **PHP >= 8.4.1** et les contraintes PHP de ses dépendances de production acceptent **8.5.10**. Conservez donc votre PHP 8.5 ; la validation des extensions sera effectuée avec Composer après le clonage. Les tests précédents ont été exécutés localement sous PHP 8.4, pas sur ce serveur PHP 8.5.

**N’utilisez pas `add-apt-repository ppa:ondrej/php` : ce PPA cible Ubuntu.** Le PHP par défaut de Debian 12 est 8.2 ; évitez les paquets génériques `php` et `php-fpm` pour ce projet. [Paquet PHP-FPM de Debian 12](https://packages.debian.org/bookworm/php-fpm).

### Outils système

Vérifiez Git, curl, unzip et SQLite, puis installez les outils manquants. `--no-upgrade` conserve les versions des paquets déjà installés explicitement cités :

```bash
git --version
curl --version
unzip -v
sqlite3 --version
apt update
apt install --no-upgrade git ca-certificates curl unzip sqlite3
```

### PHP-FPM 8.5 et extensions

Si `php8.5` est disponible, utilisez-le explicitement pour Titan :

```bash
php8.5 -v
php8.5 --ini
php8.5 -m
systemctl status php8.5-fpm --no-pager
ls -l /run/php/php8.5-fpm.sock
```

Si seul `php` existe, relevez son chemin absolu avec `command -v php` et vérifiez qu’il correspond bien à PHP 8.5.10, y compris en root. Remplacez alors `php8.5` par ce chemin dans les commandes suivantes. Ne changez pas les alternatives PHP globales du serveur.

Pour une installation standard gérée par APT, **uniquement si FPM ou des extensions manquent et si `apt-cache policy` propose les paquets 8.5 du fournisseur déjà utilisé** :

```bash
apt install --no-upgrade php8.5-fpm php8.5-curl php8.5-mbstring \
  php8.5-xml php8.5-zip php8.5-sqlite3 php8.5-bcmath php8.5-intl
systemctl enable --now php8.5-fpm
```

Examinez la liste proposée par APT avant de confirmer. Si les paquets sont introuvables, identifiez le fournisseur de votre PHP 8.5 existant. Pour un PHP fourni par un panneau, installez FPM et les extensions via ce panneau. La [procédure du dépôt PHP Sury pour Debian](https://packages.sury.org/php/README.txt) est une référence si ce fournisseur est retenu ; n’ajoutez pas un second dépôt à l’aveugle.

Vérifiez également le pool FPM réellement utilisé :

```bash
grep -R -E '^[[:space:]]*(listen|listen.owner|listen.group|user|group)[[:space:]]*=' /etc/php/8.5/fpm/pool.d/
```

La suite suppose un pool exécuté sous `www-data`, accessible à Nginx via `/run/php/php8.5-fpm.sock`. Si le pool utilise un autre utilisateur ou socket, adaptez les permissions et `fastcgi_pass` ; ne modifiez pas le pool partagé des autres sites.

### Nginx et HTTPS

Si Nginx n’est pas installé et qu’aucun panneau ni autre serveur ne gère déjà les ports 80/443 :

```bash
apt install --no-upgrade nginx
systemctl enable --now nginx
```

Pour HTTPS géré directement avec Nginx, si Certbot et son plugin ne sont pas déjà installés :

```bash
command -v certbot
apt install --no-upgrade certbot python3-certbot-nginx
```

Si Certbot est déjà fourni par Snap ou un autre installateur, conservez cette installation et vérifiez `certbot plugins`. Un panneau peut gérer lui-même les certificats ; dans ce cas, utilisez son interface HTTPS.

### Composer

S’il est déjà présent, vérifiez sa version avec l’interpréteur prévu pour Titan :

```bash
php8.5 "$(command -v composer)" --version
```

Utilisez Composer 2 récent. **Si Composer est absent**, installez-le depuis son installateur officiel avec vérification de l’empreinte :

```bash
(
  set -e
  cd /tmp
  curl -fsSL https://getcomposer.org/installer -o titan-composer-setup.php
  titan_composer_signature=$(curl -fsSL https://composer.github.io/installer.sig)
  titan_composer_actual=$(php8.5 -r 'echo hash_file("sha384", "/tmp/titan-composer-setup.php");')
  test "$titan_composer_signature" = "$titan_composer_actual"
  php8.5 /tmp/titan-composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/titan-composer-setup.php
)
php8.5 "$(command -v composer)" --version
```

### Node.js

Pour compiler l’interface sur le VPS, installez **Node.js 24** avec nvm dans le compte root. Si Node 24 est déjà disponible, vérifiez simplement `node -v` et `npm -v` et sautez le bloc d’installation. Si nvm existe déjà, chargez-le et utilisez `nvm install 24` sans réinstaller nvm.

```bash
curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.8/install.sh -o /tmp/titan-nvm-install.sh
bash /tmp/titan-nvm-install.sh
export NVM_DIR="/root/.nvm"
. "$NVM_DIR/nvm.sh"
nvm install 24
nvm use 24
node -v
npm -v
```

Node sert à compiler les fichiers CSS et JavaScript ; aucun serveur Node permanent n’est nécessaire. [Installation de nvm](https://github.com/nvm-sh/nvm#installing-and-updating).

## 3. Publier la version à déployer sur GitHub

**Sur votre ordinateur, dans le projet :**

```bash
cd /home/ash/Documents/laravel/titan-send
npm ci
npm run build
php artisan test --compact
git status
git remote -v
git branch --show-current
```

Le dépôt attendu est `https://github.com/AshDest/titan_transfer.git`, sur la branche `main`. Créez un commit avec les modifications souhaitées, dont ce document, puis envoyez vos commits :

```bash
git push origin main
```

Ne poursuivez pas si les tests, la compilation ou l’envoi échouent. Le VPS récupérera uniquement ce qui est publié sur GitHub. Les fichiers `.env`, la base SQLite locale et les secrets ne doivent pas être ajoutés à Git. `composer.lock` et `package-lock.json` doivent être versionnés.

`public/build` est généré sur le VPS à l’étape suivante : aucun transfert d’archive ni `scp` du projet n’est nécessaire.

## 4. Cloner le dépôt et installer l’application

**Sur le VPS, en root :**

Les commandes supposent que `/var/www/titan` n’existe pas encore. S’il contient déjà une installation, utilisez la procédure de mise à jour de l’étape 11.

```bash
mkdir -p /var/www
test ! -e /var/www/titan
```

Si le dernier test échoue, **arrêtez cette première installation**. Ne supprimez pas le répertoire existant pour forcer le clonage.

### Option A — Dépôt public, clonage HTTPS

```bash
git clone --branch main --single-branch \
  https://github.com/AshDest/titan_transfer.git /var/www/titan
```

Si le dépôt est privé, utilisez l’option B. Un mot de passe de compte GitHub ne permet pas d’authentifier un clonage HTTPS.

### Option B — Dépôt privé, clé de déploiement SSH

Créez une clé dédiée **sur le VPS**. Si ce fichier existe déjà, réutilisez sa clé publique au lieu de l’écraser.

```bash
install -d -m 700 /root/.ssh
ssh-keygen -t ed25519 -C "titan-vps-deploy" -f /root/.ssh/titan_github -N ''
cat /root/.ssh/titan_github.pub
```

Sur GitHub, ouvrez **AshDest/titan_transfer → Settings → Deploy keys → Add deploy key**. Collez la clé publique affichée, donnez-lui le nom `Titan VPS` et laissez **Allow write access décoché**. Cette clé sert uniquement à lire ce dépôt. [Documentation GitHub](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys).

```bash
git -c core.sshCommand='ssh -i /root/.ssh/titan_github -o IdentitiesOnly=yes' \
  clone --branch main --single-branch \
  git@github.com:AshDest/titan_transfer.git /var/www/titan

git -C /var/www/titan config core.sshCommand \
  'ssh -i /root/.ssh/titan_github -o IdentitiesOnly=yes'
```

Lors de la première connexion, vérifiez l’empreinte du serveur avec les [empreintes SSH officielles de GitHub](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints), puis acceptez-la. La configuration de clé est conservée dans ce clone pour les prochains `git pull`.

### Après le clonage — commun aux deux options

Exécutez cette partie seulement si le clonage a réussi :

```bash
cd /var/www/titan
git log -1 --oneline

mkdir -p storage/app/private storage/app/public \
  storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache

COMPOSER_ALLOW_SUPERUSER=1 php8.5 "$(command -v composer)" install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction
php8.5 "$(command -v composer)" check-platform-reqs --no-dev

if [ -s /root/.nvm/nvm.sh ]; then
  export NVM_DIR="/root/.nvm"
  . "$NVM_DIR/nvm.sh"
  nvm use 24
fi
node -v
npm ci --include=dev
npm run build
test -f public/build/manifest.json
```

Les dépendances npm de développement incluent Vite : elles sont nécessaires à la compilation, même pour une installation de production. Les dépendances PHP de développement sont exclues. Utilisez `composer install` et `npm ci` pour respecter les fichiers de verrouillage.

L’installation des dépendances s’exécute ici en root pour suivre ce premier déploiement ; le service PHP fonctionnera sous `www-data`.

## 5. Créer la configuration de production

**Sur le VPS, dans `/var/www/titan` :**

```bash
umask 027
cat > .env <<'ENV'
APP_NAME="Titan Transfer"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://titan.newtechnologyhub.org

APP_LOCALE=fr
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=fr_FR
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/titan/database.sqlite
DB_FOREIGN_KEYS=true

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_PATH=/

CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER=log
ENV

install -d -o www-data -g www-data -m 750 /var/lib/titan
test ! -e /var/lib/titan/database.sqlite
```

Si le dernier test échoue, une base existe déjà : **ne l’écrasez pas**. Pour une base neuve seulement :

```bash
install -o www-data -g www-data -m 640 /dev/null /var/lib/titan/database.sqlite

chown -R root:www-data /var/www/titan
chmod -R u=rwX,g=rX,o= /var/www/titan
chmod 640 .env
chmod 700 .git
chown -R www-data:www-data storage bootstrap/cache

php8.5 artisan key:generate --force --no-interaction
runuser -u www-data -- php8.5 artisan migrate --force --no-interaction
runuser -u www-data -- php8.5 artisan db:seed \
  --class=RolesAndPermissionsSeeder --force --no-interaction
```

La base est située hors du code et du dossier public. Conservez la clé `APP_KEY` lors des prochaines mises à jour.

Cette initialisation crée uniquement les rôles et permissions. Vous créerez ensuite vos agences, utilisateurs, taux et tranches de frais depuis l’application. Ne lancez pas `DevelopmentSeeder` en production.

Les notifications actuelles sont enregistrées directement en base ; elles ne nécessitent pas de worker. `MAIL_MAILER=log` n’envoie pas d’e-mails : configurez un service SMTP si vous ajoutez ce besoin.

## 6. Créer votre compte administrateur

**Sur le VPS, dans `/var/www/titan` :**

Remplacez `admin@newtechnologyhub.org` par votre adresse dans les deux commandes. Le premier mot de passe est aléatoire et n’est pas affiché.

```bash
php8.5 artisan tinker --execute='
$email = "admin@newtechnologyhub.org";
if (\App\Models\User::where("email", $email)->exists()) {
    throw new \RuntimeException("Ce compte existe déjà. Utilisez titan:password.");
}
\Illuminate\Support\Facades\DB::transaction(function () use ($email) {
    $user = \App\Models\User::create([
        "name" => "Administrateur Titan",
        "email" => $email,
        "password" => \Illuminate\Support\Str::password(48),
    ]);
    $user->assignRole(\App\Enums\Role::Administrator->value);
});
'

php8.5 artisan titan:password admin@newtechnologyhub.org
```

La seconde commande demande votre mot de passe deux fois, avec une saisie masquée. Minimum : 12 caractères comprenant des lettres et des chiffres. Aucun compte de démonstration n’est installé.

Puis préparez les caches et les permissions :

```bash
chown -R www-data:www-data storage bootstrap/cache /var/lib/titan
runuser -u www-data -- php8.5 artisan optimize --no-interaction
```

## 7. Ajouter le site Nginx

**Sur le VPS, en root :**

Sauvegardez d’abord la configuration :

```bash
cp -a /etc/nginx "/root/nginx-before-titan-$(date +%Y%m%d-%H%M%S)"
nginx -T 2>&1 | grep -n titan.newtechnologyhub.org
```

Si ce domaine apparaît déjà, modifiez son bloc existant ; ne créez pas un second site avec le même nom. Sinon :

```bash
cat > /etc/nginx/sites-available/titan <<'NGINX'
server {
    listen 80;
    server_name titan.newtechnologyhub.org;
    root /var/www/titan/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    access_log /var/log/nginx/titan-access.log;
    error_log /var/log/nginx/titan-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \.php(?:/|$) {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -s /etc/nginx/sites-available/titan /etc/nginx/sites-enabled/titan
nginx -t && systemctl reload nginx
```

Le dossier servi doit être **`/var/www/titan/public`**, jamais `/var/www/titan`. La configuration des autres domaines reste en place.

Vérifiez le site avant le certificat :

```bash
curl -I -H 'Host: titan.newtechnologyhub.org' http://127.0.0.1/login
```

Une réponse `200` est attendue. Ne tentez pas encore de vous connecter : les cookies de session sont réservés à HTTPS.

## 8. Activer HTTPS

**Sur le VPS :**

```bash
certbot --nginx -d titan.newtechnologyhub.org --redirect
```

Renseignez votre adresse e-mail et acceptez les conditions de Let’s Encrypt. Certbot configure le certificat et la redirection HTTP vers HTTPS.

```bash
nginx -t
systemctl reload nginx
systemctl list-timers --all | grep -i certbot
certbot renew --dry-run
```

Pour une installation APT de Certbot, activez `certbot.timer` avec `systemctl enable --now certbot.timer` s’il existe mais n’est pas actif. Si Certbot est géré autrement, vérifiez le mécanisme de renouvellement de cette installation.

Si vous réactivez ensuite le proxy Cloudflare, choisissez le mode SSL/TLS **Full (strict)**, pas Flexible.

## 9. Vérifier le fonctionnement

**Sur votre ordinateur :**

```bash
curl -I https://titan.newtechnologyhub.org/login
curl -I https://titan.newtechnologyhub.org/up
curl -I https://titan.newtechnologyhub.org/.env
```

Attendu : `200` pour `/login` et `/up`, et `403` ou `404` pour `/.env`.

Ouvrez **https://titan.newtechnologyhub.org**, connectez-vous avec votre compte administrateur, puis configurez :

1. Les agences et leurs devises.
2. Les taux de change et les tranches de frais.
3. Les utilisateurs et leurs rôles.

Vérifiez les écrans et les autorisations avant de commencer les opérations réelles. Un test de transfert en production enregistre un encaissement : utilisez un environnement séparé pour les opérations fictives.

## 10. Sauvegarder avant les prochaines modifications

**Sur le VPS :**

```bash
install -d -m 700 /root/backups/titan
titan_backup_stamp=$(date +%Y%m%d-%H%M%S)
sqlite3 /var/lib/titan/database.sqlite \
  ".backup '/root/backups/titan/database-${titan_backup_stamp}.sqlite'"
install -m 600 /var/www/titan/.env \
  "/root/backups/titan/env-${titan_backup_stamp}"
tar -czf "/root/backups/titan/storage-${titan_backup_stamp}.tar.gz" \
  -C /var/www/titan storage/app
chmod 600 /root/backups/titan/*
```

Copiez régulièrement ces sauvegardes hors du VPS. La commande SQLite `.backup` produit une sauvegarde cohérente d’une base ouverte. Conservez également le code correspondant et testez la restauration sur une installation séparée.

## 11. Mettre à jour depuis GitHub

Publiez d’abord vos changements testés sur `main` depuis votre ordinateur, puis faites la sauvegarde de l’étape 10. Ne recréez pas la base, le compte administrateur ou la clé `APP_KEY`.

**Sur le VPS, en root :**

```bash
cd /var/www/titan
git status --short
git fetch origin main
git log --oneline HEAD..origin/main
```

Si des fichiers de code ont été modifiés directement sur le serveur, résolvez ces différences avant de poursuivre. Le bloc suivant s’arrête à la première erreur. Le site reste en maintenance si une étape échoue : corrigez l’erreur avant de le remettre en service.

```bash
(
  set -e
  cd /var/www/titan
  git diff --quiet
  git diff --cached --quiet
  runuser -u www-data -- php8.5 artisan down --retry=60
  git pull --ff-only origin main

  COMPOSER_ALLOW_SUPERUSER=1 php8.5 "$(command -v composer)" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction
  php8.5 "$(command -v composer)" check-platform-reqs --no-dev

  if [ -s /root/.nvm/nvm.sh ]; then
    export NVM_DIR="/root/.nvm"
    . "$NVM_DIR/nvm.sh"
    nvm use 24
  fi
  npm ci --include=dev
  npm run build

  chown -R root:www-data /var/www/titan
  chmod -R u=rwX,g=rX,o= /var/www/titan
  chmod 640 .env
  chmod 700 .git
  chown -R www-data:www-data storage bootstrap/cache /var/lib/titan

  runuser -u www-data -- php8.5 artisan config:clear --no-interaction
  runuser -u www-data -- php8.5 artisan migrate --force --no-interaction
  runuser -u www-data -- php8.5 artisan optimize --no-interaction
  systemctl reload php8.5-fpm
  runuser -u www-data -- php8.5 artisan up
)
```

Revérifiez ensuite la connexion HTTPS et les écrans concernés. `.env` et les données restent sur le VPS. N’utilisez jamais `migrate:fresh`, `db:wipe` ou `git clean -fdx` en production. Si une migration échoue, ne restaurez pas seulement le code : vérifiez aussi la compatibilité de la base et la sauvegarde avant toute restauration.

## Dépannage

| Symptôme | Vérification |
| --- | --- |
| `Permission denied` en SSH | Utilisez la bonne clé avec `-i`, ou la connexion par mot de passe de l’étape 1. |
| `Repository not found` ou `Permission denied (publickey)` avec GitHub | Vérifiez l’URL du dépôt, la clé publique dans ses Deploy keys et la commande SSH configurée à l’étape 4. |
| `npm` introuvable ou version Node incompatible | Chargez `/root/.nvm/nvm.sh`, puis lancez `nvm use 24`. |
| Autre site ou page Nginx affichée | Vérifiez le DNS, `server_name`, le lien dans `sites-enabled` et les éventuels doublons avec `nginx -T`. |
| Erreur 502 | Vérifiez `systemctl status php8.5-fpm` et l’existence de `/run/php/php8.5-fpm.sock`. |
| Erreur 500 | Consultez `/var/www/titan/storage/logs/laravel.log` et `/var/log/nginx/titan-error.log`. |
| Base SQLite en lecture seule | `www-data` doit pouvoir écrire dans `/var/lib/titan` et dans `database.sqlite`. |
| Page sans styles | Vérifiez `public/build/manifest.json`, la compilation sur le VPS et l’absence de `public/hot`. |
| Erreur 419 à la connexion | Utilisez HTTPS ; vérifiez les cookies, la table `sessions` et `SESSION_SECURE_COOKIE=true`. |
| Échec du certificat | Vérifiez le DNS A/AAAA, le port 80 et l’accès public au domaine. |
| Composer refuse PHP | Utilisez `php8.5 "$(command -v composer)" check-platform-reqs --no-dev` ; n’ignorez pas les exigences de plateforme. |

## Références

- [Déploiement Laravel 13](https://laravel.com/docs/13.x/deployment) : dossier public, permissions et caches.
- [Certbot avec Nginx](https://eff-certbot.readthedocs.io/en/stable/using.html#nginx) : installation et renouvellement du certificat.
- [Dépôt PHP Sury pour Debian](https://packages.sury.org/php/README.txt) : instructions du fournisseur, si un dépôt supplémentaire est nécessaire.
- [Installation officielle de Composer](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md) : vérification de l’installateur.

Ce document est une procédure à exécuter ; il ne signifie pas que le VPS a déjà été configuré.
