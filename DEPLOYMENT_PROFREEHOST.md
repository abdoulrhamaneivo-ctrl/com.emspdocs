# Déploiement ProFreeHost - EMSP Docs

Le guide complet de déploiement est maintenu dans :

`_non_prod/docs/DEPLOYMENT_PROFREEHOST.md`

Résumé rapide :

1. Importer `_non_prod/db/emsp_docs_full.sql` dans phpMyAdmin.
2. Configurer `.env` avec `APP_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, Brevo et le domaine école.
3. Envoyer le code PHP, `admin/`, `ajax/`, `assets/`, `errors/`, `includes/`, `vendor/`, `uploads/`, `.htaccess`, `manifest.json`, `sw.js`.
4. Ne pas exposer `.git/`, `node_modules/`, `test-results/`, `.phpunit.cache/`, `.qodo/`, fichiers `.tmp-*`.
5. Vérifier inscription, connexion, dépôt, modération, preview, téléchargement POST+CSRF, journal, médiathèque, institution, formations et PWA.
