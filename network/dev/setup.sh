#!/usr/bin/env bash
# FILE: setup.sh
# PATH: network/dev/setup.sh
#
# Rebuilds the local test site from scratch: subdirectory multisite (like production), a
# "deprogrammer" site with sample Articles posts and a privacy page, the plugin activated
# on that site only, the network page published, and one highlighted video. Feeds come from dev/fixtures
# (regenerate with: node dev/make-fixtures.mjs).
#   bash dev/setup.sh            → http://localhost:8088/deprogrammer/network/
set -euo pipefail
cd "$(dirname "$0")"
wpc() { docker compose --profile cli run --rm -T cli "$@" 2>&1 | grep -v -E '^ ?Container|^$' || true; }
val() { docker compose --profile cli run --rm -T cli "$@" 2>/dev/null | tail -1; }
U=--url=http://localhost:8088/deprogrammer/

node ../scripts/build-plugin.mjs
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d
until docker compose exec -T db mysqladmin ping -uroot -proot --silent >/dev/null 2>&1; do sleep 2; done
until curl -s -o /dev/null http://localhost:8088/; do sleep 2; done
sleep 3

wpc core multisite-install --url=localhost:8088 --title="Keith Ratner (local)" --admin_user=admin --admin_password=admin --admin_email=dev@example.com --skip-email --subdomains=false
wpc config set SUBDOMAIN_INSTALL false --raw
docker compose exec -T wordpress bash -c 'cat > /var/www/html/.htaccess <<"HT"
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
HT
chown www-data /var/www/html/.htaccess'
wpc site create --slug=deprogrammer --title="Deprogrammer.org" --email=dev@example.com
wpc rewrite structure '/%postname%/' $U
CAT=$(val term create category Articles --slug=articles --porcelain $U)
while IFS= read -r t; do
  wpc post create --post_type=post --post_status=publish --post_category="$CAT" --post_title="$t" \
    --post_content="<p>When we meet information that confirms what we already believe, our brains reward us. This piece looks at how that works, why it's hard to notice in ourselves, and practical ways to counter it when following the news.</p>" $U </dev/null >/dev/null
done <<'TITLES'
The Polarizing Pull of Confirmation Bias: How We Seek Out Validating Facts
Filter Bubbles and Echo Chambers: How Media Drives Political Polarization
Escaping the Filter Bubble: How Algorithms Isolate Us from Opposing Views
How Emotion Fuels the Spread of Disinformation
Understanding Negativity Bias: The Evolutionary Pull of Bad News
Understanding Social Proof Bias: When Popular = True
TITLES
PP=$(val post create --post_type=page --post_status=publish --post_title="Privacy Policy" --porcelain $U)
wpc option update wp_page_for_privacy_policy "$PP" $U
wpc plugin activate channel-network $U
wpc channel-network create-page $U
PID=$(val post list --post_type=page --meta_key=_cn_network_page --meta_value=1 --field=ID $U)
wpc post update "$PID" --post_status=publish $U
wpc channel-network refresh $U
# Seed the Highlighted section (title/channel come from YouTube's oEmbed; needs internet).
wpc channel-network highlights add "https://www.youtube.com/live/fttOhpURQGg?si=dev-seed" $U
wpc channel-network status $U
echo "→ http://localhost:8088/deprogrammer/network/   (wp-admin: http://localhost:8088/deprogrammer/wp-admin/  admin / admin)"
