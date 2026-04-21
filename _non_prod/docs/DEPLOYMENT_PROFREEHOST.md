# Deployment ProFreeHost - EMSP Docs

Ce document explique quel fichier config utiliser, comment deployer, et comment connecter Brevo.

## 1) Quel fichier est le bon ?

- Fichier principal a modifier :
  `emsp_plateforme_docs/admin/config/config.php`
  C'est la source de verite (DB + Brevo + BASE_URL + PRODUCTION).

- Fichier de connexion :
  `emsp_plateforme_docs/admin/config/dbcon.php`
  Il lit automatiquement `config.php`. Tu ne modifies pas DB ici.

En resume : tu modifies **config.php** uniquement.

---

## 2) Pre-requis ProFreeHost

1) Creer la base de donnees
2) Creer un utilisateur MySQL
3) Donner les droits a cet utilisateur
4) Importer le dump SQL

---

## 3) Configuration DB (config.php)

Ouvre `emsp_plateforme_docs/admin/config/config.php` et mets :

```php
// Base de donnees
define('DB_HOST', 'localhost');
define('DB_USER', 'TON_USER_PROFREEHOST');
define('DB_PASS', 'TON_MDP_PROFREEHOST');
define('DB_NAME', 'TON_NOM_BDD_PROFREEHOST');

// URL de base (sans slash final)
define('BASE_URL', 'https://ton-domaine.profreehost.com');

// Mode prod
define('PRODUCTION', true);
```

---

## 4) Importer la base

Utilise ton dump SQL (phpMyAdmin) :
- Importe le fichier `emsp_plateforme_docs (7).sql` dans la base

Optionnel (si besoin) :
- Importer `emsp_plateforme_docs/admin/migration_school_domains.sql`

---

## 5) Dossiers upload et droits

Assure-toi que ces dossiers existent et sont en 755 :

- `uploads/`
- `uploads/documents/`
- `uploads/media/`
- `uploads/media/journal/`
- `uploads/media/institution/`
- `uploads/profiles/`
- `uploads/student-cards/`

---

## 6) Brevo (Email)

### Etapes Brevo
1) Creer un compte Brevo
2) Creer une cle API
3) Verifier ton domaine d'envoi si possible

### Configuration dans config.php

```php
// Brevo API (email)
define('BREVO_API_KEY', 'TA_CLE_BREVO');
define('BREVO_FROM_EMAIL', 'noreply@ton-domaine.com');
define('BREVO_FROM_NAME', 'EMSP Docs');
```

Le code utilise l'API Brevo via cURL.

---

## 7) Domaines email ecole

Tu peux configurer les domaines autorises depuis l'admin :
- Menu admin > "Domaines email"
- Ajoute : @emsp.int, @emsp.edu, etc.

Le systeme accepte tous les domaines actifs dans la table `school_email_domains`.

---

## 8) Checklist apres deploiement

- Connexion admin OK
- Upload document OK
- Preview PDF OK
- Journal (Quill) OK
- Institution (blocs) OK
- Emails Brevo OK (test inscription)

---

## 9) Depannage rapide

- Erreur 500 : verifier `config.php` (host/user/pass) + droits uploads
- Email non recu : verifier cle Brevo + domaine from
- Caractere casse : verifier `utf8mb4` + importer le dump en UTF-8

---

Si tu veux, je peux ajouter un script test d'email Brevo ou un seed
`app_settings` par defaut.


