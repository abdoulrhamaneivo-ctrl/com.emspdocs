# EMSP Docs â€” Cahier des charges (ultra complet)

Version : 1.0  
Date : 2026-03-15  
Projet : **EMSP Docs** (plateforme de dÃ©pÃ´t/consultation de documents pÃ©dagogiques)

---

## 1) Objectifs, vision, pÃ©rimÃ¨tre

### 1.1 Vision
CrÃ©er une plateforme web simple, rapide et sÃ©curisÃ©e permettant aux **Ã©tudiants** de :
- sâ€™inscrire / se connecter,
- dÃ©poser des documents (cours, TD, corrections, concours, examens),
- rechercher, prÃ©visualiser et tÃ©lÃ©charger des documents,
- interagir (likes, commentaires, rÃ©actions),
- organiser (favoris),
avec une **modÃ©ration** (validation) assurÃ©e par un **admin/modÃ©rateur**.

### 1.2 Objectifs mÃ©tier
- Centraliser les ressources de lâ€™Ã©cole (documents, mÃ©dias, journal).
- RÃ©duire la friction de partage (recherche, filtres, tags acadÃ©miques).
- Garantir la qualitÃ© via un workflow de validation.
- Tracer lâ€™usage (tÃ©lÃ©chargements, likes, statistiques).

### 1.3 Hors pÃ©rimÃ¨tre (par dÃ©faut)
Ces Ã©lÃ©ments ne sont pas requis sauf dÃ©cision explicite :
- paiement / abonnements,
- messagerie temps rÃ©el,
- stockage externe (S3, Drive),
- conversion serveur Officeâ†’PDF (LibreOffice) sur ProFreeHost,
- moteur de recherche full-text avancÃ© (Elastic).

---

## 2) Contraintes techniques (non nÃ©gociables)

- **PHP procÃ©dural** (PHP 8.x), **MySQLi** (pas de PDO, pas dâ€™OOP, pas de framework).
- Bootstrap 5 **local** : `assets/css/bootstrap5.min.css` + JS local.
- Bootstrap Icons **local** : `assets/css/bootstrap-icons.min.css`.
- HÃ©bergement mutualisÃ© type ProFreeHost/InfinityFree :
  - banniÃ¨re publicitaire injectÃ©e en haut des pages,
  - limites upload (souvent 5â€“10 Mo),
  - performances variables,
  - permissions fichiers/dossiers limitÃ©es (Ã©viter `chmod()` agressif).
- JS : `fetch()` avec `credentials:'include'` pour toute route dÃ©pendant de session.
- CDN **interdit** : jsDelivr. CDN autorisÃ©s : `cdnjs.cloudflare.com`, `unpkg.com` (mais idÃ©alement **tout en local**).

---

## 3) Utilisateurs, rÃ´les, droits (RBAC)

### 3.1 RÃ´les
- **Visiteur (guest)** : non connectÃ©.
- **Ã‰tudiant (etudiant)** : compte standard.
- **ModÃ©rateur (moderateur)** : valide/rejette documents, modÃ¨re commentaires, gÃ¨re une partie du contenu.
- **Administrateur (admin)** : droits complets + paramÃ¨tres.

### 3.2 Matrice des droits (rÃ©sumÃ©)
| Fonction | Guest | Ã‰tudiant | ModÃ©rateur | Admin |
|---|---:|---:|---:|---:|
| Voir documents publics approuvÃ©s | âœ… | âœ… | âœ… | âœ… |
| DÃ©poser un document | âŒ | âœ… | âœ… | âœ… |
| TÃ©lÃ©charger un document privÃ© | âŒ | âœ… (si autorisÃ©) | âœ… | âœ… |
| Valider/Rejeter documents | âŒ | âŒ | âœ… | âœ… |
| Valider utilisateurs (mÃ©thode carte) | âŒ | âŒ | âœ… | âœ… |
| GÃ©rer rÃ©fÃ©rentiels (filiÃ¨res/licences/modules/matiÃ¨res) | âŒ | âŒ | âœ… | âœ… |
| ParamÃ¨tres plateforme (banniÃ¨re / seuil badges) | âŒ | âŒ | âŒ | âœ… |

---

## 4) Carte des pages / routes (front + admin)

### 4.1 Front-office (principales)
- `index.php` : accueil
- `formations.php` : page formations (contenu institutionnel)
- `institution.php` : page institution (blocs dynamiques)
- `news-blog.php` + `news-article.php` : journal / articles
- `mediatheque.php` : galerie mÃ©dias publique (images/vidÃ©os/liens)
- `concours.php` : documents type concours/examens (selon implÃ©mentation)
- `faq.php` : FAQ

### 4.2 Espace Ã©tudiant
- `login.php` / `logout.php` : authentification
- `register.php` / `registercode.php` : inscription (email Ã©cole ou carte)
- `verify-email.php` / `resend-verification.php` : vÃ©rification email (si mÃ©thode carte)
- `forgot-password.php` / `reset-password*.php` : rÃ©initialisation mot de passe
- `dashboard.php` : tableau de bord Ã©tudiant + notifications
- `bibliotheque.php` : catalogue + filtres
- `document.php?id=XX` : page dÃ©tail + preview + interactions
- `upload.php` + `upload-code.php` : dÃ©pÃ´t document + envoi
- `mes-favoris.php` : favoris
- `mon-profil.php` / `profil-public.php` : profil
- `notif-marquer-lues.php` : marquer notifications lues

### 4.3 Endpoints dâ€™actions (AJAX / JSON)
- `telecharger.php?id=XX&preview=1` : stream inline pour PDF/images
- `telecharger.php?id=XX&raw=1` : stream bytes pour preview navigateur (DOCX/XLSX/TEXT)
- `telecharger.php?id=XX&download=1` : tÃ©lÃ©chargement forcÃ© (POST + CSRF)
- `like-handler.php` : like/unlike document (JSON)
- `reaction-handler.php` : rÃ©actions sur commentaires (JSON)
- `journal-action.php` : vote sondage / participation dÃ©fi (JSON)
- `pending-status.php` : statut validation (selon code)

### 4.4 Back-office (admin/)
- `admin/index.php` : dashboard admin
- `admin/pending-documents.php` : validation documents
- `admin/view-documents.php` : tous les documents (suppression/gestion)
- `admin/pending-users.php` : validation comptes (mÃ©thode carte)
- `admin/view-users.php` + `admin/add-user.php` + `admin/edit-user.php` : gestion utilisateurs
- `admin/view-filieres.php` + `admin/add-filiere.php` : filiÃ¨res
- `admin/view-licences.php` + `admin/add-licence.php` : licences/niveaux + mapping filiÃ¨res
- `admin/view-modules.php` + `admin/add-module.php` : modules
- `admin/view-matieres.php` + `admin/add-matiere.php` : matiÃ¨res
- `admin/journal.php` + `admin/add-journal.php` : journal/news (Quill)
- `admin/mediatheque.php` + `admin/add-media.php` : mÃ©dias
- `admin/media-categories.php` : catÃ©gories mÃ©dias
- `admin/edit-institution.php` : blocs institution (Sortable + Quill)
- `admin/comments-moderation.php` : modÃ©ration commentaires
- `admin/badge-or-batch.php` : badges (batch)
- `admin/stats.php` : stats
- `admin/school-domains.php` : domaines email Ã©cole
- `admin/settings.php` : paramÃ¨tres (banniÃ¨re, seuil badge)
- `admin/repair-encoding.php` : maintenance encodage (optionnel)

---

## 5) Objets mÃ©tier (dictionnaire fonctionnel)

### 5.1 Utilisateur
Champs attendus (extraits observÃ©s) :
- identitÃ© : `first_name`, `last_name`
- accÃ¨s : `email`, `password_hash`, `role`, `status`
- profil : `photo_path`, `filiere_id`, `licence_id`
- inscription : `registration_method` (`school_email` / `manual_card`), `student_card_path`
- vÃ©rification : `verification_token`, `email_verified_at`, `status_updated_at`

Statuts usuels :
- `active` : compte utilisable
- `pending` : en attente de validation (souvent mÃ©thode carte)
- `inactive` / `blocked` (si implÃ©mentÃ©)

### 5.2 Document
Champs observÃ©s cÃ´tÃ© dÃ©pÃ´t :
- metadata : `title`, `description`, `doc_type`, `semester`
- rattachements acadÃ©miques : `filiere_id`, `licence_id`, `module_id`, `matiere_id`
- examen : `exam_session`, `exam_section`, `exam_year`
- fichier : `file_path`, `mime_type`, `file_size_bytes`, `file_hash`
- contrÃ´le : `status` (`pending` / `approved` / `rejected`), `is_public`
- stats : `download_count`, `like_count` (+ Ã©ventuels `view_count`)

### 5.3 RÃ©fÃ©rentiels
- **FiliÃ¨re** : `id`, `name`, `status`
- **Licence/Niveau** : `id`, `name`, `status`
- **Licenceâ†”FiliÃ¨re** : table pivot `licence_filieres (licence_id, filiere_id)`
- **Module** : `id`, `name`, `licence_id`, `status`
- **MatiÃ¨re** : `id`, `name`, `module_id`, `status`

### 5.4 Interactions
- Favoris : table `favorites (user_id, document_id, created_at)`
- Likes document : `document_likes (user_id, document_id, created_at)` + `documents.like_count`
- Commentaires : `comments (...)` avec `status` (ex : `visible`, `hidden`, `pending`)
- RÃ©actions commentaires : `comment_reactions (comment_id, user_id, reaction, created_at)`
- Notifications : `notifications (user_id, type, document_id, from_user_id, message, is_read, created_at)`

### 5.5 Journal / News
Tables observÃ©es :
- `journal (id, type, title, content, status, author_id/admin_id?, created_at, ...)`
- `journal_options (id, journal_id, label)` pour sondages
- `journal_votes (id, journal_id, option_id, user_id, created_at)`
- `journal_defis (id, journal_id, user_id, note, created_at)`

### 5.6 MÃ©dias
Tables observÃ©es :
- `media_categories (id, name, description, created_at)`
- `media (id, title, description, type, file_path, category, category_id, is_public, status, created_by, created_at)`

### 5.7 Institution (contenu dynamique)
- `institution_blocs (id, section_key, section_label, bloc_type, contenu, image_path, config_json, ordre, visible, updated_at)`
Types : `texte`, `image`, `galerie`, `stats`, `citation`, `colonnes`, `separateur`.

---

## 6) Workflows (parcours) â€” ultra dÃ©taillÃ©s

> Chaque parcours dÃ©crit : PrÃ©-conditions â†’ Ã‰tapes â†’ Variantes â†’ Erreurs â†’ CritÃ¨res dâ€™acceptation.

### Parcours A â€” Visiteur â†’ Inscription (email Ã©cole)
**PrÃ©-conditions**
- Lâ€™utilisateur nâ€™est pas connectÃ©.
- Des domaines email Ã©cole sont configurÃ©s (table `school_email_domains` ou constante).

**Ã‰tapes**
1. Ouvrir `register.php`.
2. Saisir prÃ©nom/nom/email/mot de passe/confirmation.
3. Choisir filiÃ¨re + niveau.
4. Choisir mÃ©thode : **email Ã©cole**.
5. Soumettre le formulaire.
6. Le systÃ¨me valide :
   - format email,
   - domaine autorisÃ©,
   - mot de passe â‰¥ 8,
   - filiÃ¨re/licence actives.
7. CrÃ©ation du compte en `status=active`.
8. Redirection vers `login.php` avec message succÃ¨s.

**Variantes**
- Email dÃ©jÃ  utilisÃ© â†’ erreur, proposer login/reset.
- Domaine non autorisÃ© â†’ erreur (afficher domaines acceptÃ©s).

**CritÃ¨res dâ€™acceptation**
- Le compte apparaÃ®t en BDD avec `role='etudiant'`, `status='active'`.
- Lâ€™utilisateur peut se connecter immÃ©diatement.

---

### Parcours B â€” Visiteur â†’ Inscription (carte Ã©tudiante)
**Objectif**
Permettre lâ€™inscription dâ€™un Ã©tudiant ne disposant pas (encore) dâ€™un email Ã©cole.

**Ã‰tapes**
1. `register.php` â†’ choisir **carte**.
2. Upload carte (JPG/PNG/PDF, taille limitÃ©e).
3. Le compte est crÃ©Ã© en `status=pending`.
4. Envoi email de vÃ©rification (Brevo) avec `verification_token`.
5. Lâ€™Ã©tudiant confirme via `verify-email.php`.
6. Le compte reste pending jusquâ€™Ã  validation admin/modÃ©rateur.

**Erreurs**
- Upload KO / trop lourd / mime non acceptÃ©.
- Email dâ€™envoi indisponible â†’ le compte existe, prÃ©voir â€œrenvoyer emailâ€.

**CritÃ¨res dâ€™acceptation**
- Le compte est visible dans `admin/pending-users.php`.
- AprÃ¨s validation, `status` passe Ã  `active`.

---

### Parcours C â€” Connexion / DÃ©connexion
**Connexion**
1. `login.php`
2. VÃ©rifier email + mot de passe.
3. CrÃ©er session : `$_SESSION['auth']=true`, `auth_user`, `auth_role`.
4. Rediriger selon rÃ´le :
   - Ã©tudiant â†’ `dashboard.php`
   - admin/modÃ©rateur â†’ `admin/index.php` (ou lien dans dropdown)

**DÃ©connexion**
- `logout.php` dÃ©truit session + redirection accueil/login.

---

### Parcours D â€” Ã‰tudiant â†’ DÃ©poser un document
**Ã‰tapes**
1. `upload.php` (formulaire).
2. Choisir : titre, type, semestre, filiÃ¨re/licence/module/matiÃ¨re, public/privÃ©.
3. Upload fichier (PDF/DOCX/XLSX/PPTX/images/zipâ€¦ selon liste).
4. `upload-code.php` :
   - CSRF,
   - taille max (min serveur/app),
   - mime rÃ©el (`finfo`/helper),
   - anti-doublon par `file_hash`,
   - stockage dans `uploads/documents/`,
   - insert BDD : `status='pending'`.
5. Message succÃ¨s + retour dashboard.

**Erreurs**
- Fichier trop lourd â†’ message clair + limite.
- Mime non acceptÃ© â†’ refuser.
- Dossier upload absent â†’ crÃ©er (si possible) ou afficher erreur.

**CritÃ¨res dâ€™acceptation**
- Document apparaÃ®t en BDD en `pending`.
- Document non visible en bibliothÃ¨que publique tant quâ€™il nâ€™est pas approuvÃ©.

---

### Parcours E â€” ModÃ©rateur/Admin â†’ Valider/Rejeter un document
**Ã‰tapes**
1. `admin/pending-documents.php` liste `documents.status='pending'`.
2. PrÃ©visualiser (si possible) + tÃ©lÃ©charger.
3. Actions :
   - **Approuver** : passe `status='approved'`, (option : notifier uploader).
   - **Rejeter** : passe `status='rejected'` + `rejection_reason`.
4. Le document devient visible en bibliothÃ¨que selon `is_public`.

**CritÃ¨res**
- Les Ã©tats se reflÃ¨tent dans `document.php` (badge â€œEn attenteâ€, â€œRejetÃ©â€).

---

### Parcours F â€” Ã‰tudiant â†’ Recherche et consultation bibliothÃ¨que
**Ã‰tapes**
1. `bibliotheque.php` affiche grille paginÃ©e.
2. Filtrer : filiÃ¨re/licence/module/matiÃ¨re/semestre/type + recherche texte.
3. Cliquer une carte â†’ `document.php?id=XX`.

**CritÃ¨res**
- La grille est responsive (3â†’2â†’1 colonnes).
- Titres longs tronquÃ©s, pas de dÃ©bordement.

---

### Parcours G â€” Ã‰tudiant â†’ PrÃ©visualiser un document (multi-formats)
**Principe**
PrÃ©visualisation **sans conversion serveur** (compatible mutualisÃ©).

**PDF**
- Tentative PDF.js (local) avec `withCredentials`.
- Si Ã©chec : fallback `iframe` (aperÃ§u navigateur).

**DOCX**
- `fetch telecharger.php?id=XX&raw=1` â†’ rendu HTML via **Mammoth.js**
- Nettoyage HTML via **DOMPurify**.

**XLSX**
- `fetch raw=1` â†’ lecture via **SheetJS (XLSX)** â†’ rendu table HTML (limitÃ©).

**Textes**
- `fetch raw=1` â†’ affichage `<pre>` (UTF-8).

**Erreurs**
- Si format non supportÃ© : afficher â€œAperÃ§u non disponibleâ€ + bouton TÃ©lÃ©charger.

**CritÃ¨res dâ€™acceptation**
- Le simple fait dâ€™ouvrir `document.php` ne dÃ©clenche **aucun tÃ©lÃ©chargement**.
- Le tÃ©lÃ©chargement dÃ©marre uniquement via clic sur â€œTÃ©lÃ©chargerâ€.

---

### Parcours H â€” TÃ©lÃ©charger un document (sans auto-download)
**RÃ¨gle**
- `download=1` doit Ãªtre **POST + CSRF**.
- Un GET direct ne doit pas streamer le fichier (Ã©vite tÃ©lÃ©chargement auto).

**CritÃ¨res**
- Le refresh dâ€™une page ne doit pas redÃ©clencher un download.

---

### Parcours I â€” Interactions : favoris / likes / commentaires / rÃ©actions
**Favoris**
- Ajouter/retirer depuis `document.php`.
- Liste via `mes-favoris.php`.

**Like**
- AJAX `like-handler.php` (CSRF + session).
- MAJ `documents.like_count` + notification Ã  lâ€™auteur.

**Commentaires**
- Ajout (front) + modÃ©ration (admin) si activÃ©.

**RÃ©actions**
- AJAX `reaction-handler.php` sur commentaires.

---

## 7) Exigences non fonctionnelles (qualitÃ©)

### 7.1 UX / Responsive
- ZÃ©ro dÃ©bordement horizontal (mobile first).
- Boutons tactiles â‰¥ 40px.
- Formulaires lisibles (inputs â‰¥ 16px sur mobile).
- Modals/Dropdown z-index correct (banniÃ¨re host + navbar).

### 7.2 SÃ©curitÃ©
- CSRF sur toutes actions POST.
- Validation serveur (tailles, mime rÃ©el, ids numÃ©riques).
- TÃ©lÃ©chargement sÃ©curisÃ© via `telecharger.php` (contrÃ´le dâ€™accÃ¨s + chemin rÃ©el).
- Pas dâ€™include chemins relatifs instables : utiliser `__DIR__`.
- Ã‰chapper toute donnÃ©e user via `htmlspecialchars(..., 'UTF-8')`.

### 7.3 Performance
- Pagination sur listes (bibliothÃ¨que, admin documents, users).
- Index BDD recommandÃ©s :
  - `documents(status, is_public, created_at)`
  - `favorites(user_id, document_id)`
  - `document_likes(user_id, document_id)`
  - `notifications(user_id, is_read, created_at)`

### 7.4 CompatibilitÃ© hÃ©bergement (ProFreeHost)
- TolÃ©rer banniÃ¨re injectÃ©e (offset top).
- Pas de dÃ©pendance CDN fragile.
- Logs minimaux cÃ´tÃ© serveur (fichier `debug_*.txt` si besoin).

---

## 8) CritÃ¨res dâ€™acceptation globaux (Go/No-Go)

- Aucune page ne doit Ãªtre vide Ã  cause dâ€™un layout cassÃ© (divs fermÃ©s correctement).
- Encodage : affichage franÃ§ais lisible (UTF-8, pas de mojibake).
- `telecharger.php` :
  - `?preview=1` fonctionne pour PDF/images,
  - `?raw=1` fonctionne pour les previews navigateur,
  - `?download=1` tÃ©lÃ©charge uniquement aprÃ¨s clic (POST+CSRF).
- Workflow modÃ©ration opÃ©rationnel : dÃ©pÃ´t â†’ pending â†’ approve/reject.
- Admin : gestion users + rÃ©fÃ©rentiels + contenus accessible et utilisable.

---

## 9) Livrables attendus
- Code PHP + assets locaux
- Base de donnÃ©es (schÃ©ma + donnÃ©es initiales)
- Guide dÃ©ploiement ProFreeHost (`DEPLOYMENT_PROFREEHOST.md`)
- Cahier des charges (ce document) + checklist QA



