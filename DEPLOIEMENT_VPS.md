# Déployer Titan Transfer sur le VPS

Destination : **https://titan.newtechnologyhub.org**  
Adresse du VPS : **102.223.209.43**  
Connexion : **root**

Cette procédure réalise une première installation avec une **base SQLite vide**, Nginx et PHP 8.4. Elle ne transfère pas les clients, opérations, comptes ni mots de passe de votre poste local.

Les commandes d’installation ci-dessous ciblent **Ubuntu 24.04**, avec Nginx géré directement sur le serveur. Le système du VPS n’a pas encore été vérifié faute d’accès SSH. Si le serveur utilise une autre distribution ou un panneau comme Plesk, Hestia ou aaPanel, adaptez l’installation et la création du site à cet environnement avant de poursuivre. Ne remplacez pas la configuration des autres sites.

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

**Sur le VPS :**

```bash
cat /etc/os-release
php -v
command -v nginx
ss -ltnp
```

L’absence de PHP n’est pas bloquante à cette étape. Vérifiez les programmes qui utilisent déjà les ports 80 et 443. Si Nginx est présent, examinez ses sites avec `nginx -T` avant d’ajouter Titan.

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

## 2. Installer PHP, Nginx et Composer

**Sur le VPS Ubuntu 24.04, en root :**

Le `composer.lock` actuel contient des dépendances nécessitant **PHP >= 8.4.1**. PHP 8.3 ne suffit donc pas pour cette version du projet. Ubuntu 24.04 fournit PHP 8.3 par défaut ; les commandes suivantes ajoutent le dépôt PHP tiers maintenu par Ondřej Surý pour installer PHP 8.4.

Si PHP 8.4 et Nginx sont déjà gérés par un panneau d’hébergement, utilisez les outils de ce panneau au lieu de cette installation.

```bash
apt update
apt install -y software-properties-common ca-certificates curl unzip sqlite3
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y nginx composer php8.4-cli php8.4-fpm php8.4-curl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-sqlite3 \
  php8.4-bcmath php8.4-intl certbot python3-certbot-nginx

php8.4 -v
php8.4 /usr/bin/composer --version
systemctl enable --now php8.4-fpm nginx
```

La suite utilise explicitement `php8.4`, sans changer la version PHP des autres applications.

## 3. Préparer et envoyer l’application

**Sur votre ordinateur, dans le projet :**

```bash
cd /home/ash/Documents/laravel/titan-send
npm ci
npm run build
php artisan test --compact
```

Utilisez la version de Node compatible avec votre projet. Sur le poste actuel, si nécessaire :

```bash
export PATH="/home/ash/.nvm/versions/node/v20.19.0/bin:$PATH"
```

Relancez ensuite les commandes npm précédentes. Ne poursuivez pas si la compilation ou les tests échouent.

Créez l’archive du code courant et des fichiers compilés :

```bash
tar \
  --exclude='bootstrap/cache/*.php' \
  --exclude='public/hot' \
  --exclude='public/storage' \
  -czf /tmp/titan-release.tar.gz \
  app artisan bootstrap composer.json composer.lock config \
  database/migrations database/seeders database/factories \
  public resources routes

scp -o IdentitiesOnly=yes /tmp/titan-release.tar.gz root@102.223.209.43:/root/
```

Pour `scp`, utilisez la même option `-i` que pour SSH si nécessaire. Pour une connexion par mot de passe uniquement, remplacez `-o IdentitiesOnly=yes` par `-o PubkeyAuthentication=no`.

L’archive exclut la base locale, `.env`, les journaux, les sessions, `node_modules` et `vendor`.

## 4. Installer les fichiers sur le VPS

**Sur le VPS, en root :**

Ces commandes supposent que `/var/www/titan` n’existe pas encore. S’il contient déjà une installation, sauvegardez-la et préparez une mise à jour au lieu de relancer cette première installation.

```bash
test ! -e /var/www/titan && mkdir -p /var/www/titan
```

Si cette commande échoue, **ne continuez pas l’extraction** dans le répertoire existant.

```bash
tar -xzf /root/titan-release.tar.gz -C /var/www/titan
cd /var/www/titan

mkdir -p storage/app/private storage/app/public \
  storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache

COMPOSER_ALLOW_SUPERUSER=1 php8.4 /usr/bin/composer install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction

php8.4 /usr/bin/composer check-platform-reqs --no-dev
```

Installez les versions verrouillées avec `composer install`, sans lancer `composer update` sur le VPS. L’installation Composer s’exécute ici en root pour suivre ce premier déploiement ; le service PHP fonctionnera sous `www-data`.

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
chown -R www-data:www-data storage bootstrap/cache

php8.4 artisan key:generate --force --no-interaction
runuser -u www-data -- php8.4 artisan migrate --force --no-interaction
runuser -u www-data -- php8.4 artisan db:seed \
  --class=RolesAndPermissionsSeeder --force --no-interaction
```

La base est située hors du code et du dossier public. Conservez la clé `APP_KEY` lors des prochaines mises à jour.

Cette initialisation crée uniquement les rôles et permissions. Vous créerez ensuite vos agences, utilisateurs, taux et tranches de frais depuis l’application. Ne lancez pas `DevelopmentSeeder` en production.

Les notifications actuelles sont enregistrées directement en base ; elles ne nécessitent pas de worker. `MAIL_MAILER=log` n’envoie pas d’e-mails : configurez un service SMTP si vous ajoutez ce besoin.

## 6. Créer votre compte administrateur

**Sur le VPS, dans `/var/www/titan` :**

Remplacez `admin@newtechnologyhub.org` par votre adresse dans les deux commandes. Le premier mot de passe est aléatoire et n’est pas affiché.

```bash
php8.4 artisan tinker --execute='
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

php8.4 artisan titan:password admin@newtechnologyhub.org
```

La seconde commande demande votre mot de passe deux fois, avec une saisie masquée. Minimum : 12 caractères comprenant des lettres et des chiffres. Aucun compte de démonstration n’est installé.

Puis préparez les caches et les permissions :

```bash
chown -R www-data:www-data storage bootstrap/cache /var/lib/titan
runuser -u www-data -- php8.4 artisan optimize --no-interaction
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
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
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
systemctl enable --now certbot.timer
certbot renew --dry-run
```

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

Pour les mises à jour : sauvegardez, passez en maintenance, remplacez le code sans écraser `.env`, `/var/lib/titan` ni `storage`, installez les dépendances verrouillées, exécutez `migrate --force`, reconstruisez les caches et remettez en service. N’utilisez jamais `migrate:fresh` ni `db:wipe` sur les données de production.

## Dépannage

| Symptôme | Vérification |
| --- | --- |
| `Permission denied` en SSH | Utilisez la bonne clé avec `-i`, ou la connexion par mot de passe de l’étape 1. |
| Autre site ou page Nginx affichée | Vérifiez le DNS, `server_name`, le lien dans `sites-enabled` et les éventuels doublons avec `nginx -T`. |
| Erreur 502 | Vérifiez `systemctl status php8.4-fpm` et l’existence de `/run/php/php8.4-fpm.sock`. |
| Erreur 500 | Consultez `/var/www/titan/storage/logs/laravel.log` et `/var/log/nginx/titan-error.log`. |
| Base SQLite en lecture seule | `www-data` doit pouvoir écrire dans `/var/lib/titan` et dans `database.sqlite`. |
| Page sans styles | Vérifiez `public/build/manifest.json`, la compilation locale et l’absence de `public/hot`. |
| Erreur 419 à la connexion | Utilisez HTTPS ; vérifiez les cookies, la table `sessions` et `SESSION_SECURE_COOKIE=true`. |
| Échec du certificat | Vérifiez le DNS A/AAAA, le port 80 et l’accès public au domaine. |
| Composer refuse PHP | Utilisez `php8.4 /usr/bin/composer check-platform-reqs --no-dev` ; n’ignorez pas les exigences de plateforme. |

## Références

- [Déploiement Laravel 13](https://laravel.com/docs/13.x/deployment) : dossier public, permissions et caches.
- [Certbot avec Nginx](https://eff-certbot.readthedocs.io/en/stable/using.html#nginx) : installation et renouvellement du certificat.
- [Dépôt PHP Ondřej Surý pour Ubuntu](https://launchpad.net/~ondrej/+archive/ubuntu/php) : installation de PHP 8.4.

Ce document est une procédure à exécuter ; il ne signifie pas que le VPS a déjà été configuré.
