<?php
$page_title = 'FAQ';
include __DIR__ . '/includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1>FAQ EMSP Docs</h1>
        <p class="mb-0">
            Questions frequentes sur les comptes, les depots, la moderation et la consultation des documents.
        </p>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="accordion" id="faqAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q1">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1" aria-expanded="true" aria-controls="a1">
                                Comment creer un compte etudiant ?
                            </button>
                        </h2>
                        <div id="a1" class="accordion-collapse collapse show" aria-labelledby="q1" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Utilise le bouton <strong>Inscription</strong>, puis choisis une methode:
                                email ecole ou validation par carte etudiante. Renseigne les informations demandees,
                                puis confirme ton mot de passe avant l'envoi du formulaire.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q2">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2" aria-expanded="false" aria-controls="a2">
                                Pourquoi mon compte ne se connecte pas tout de suite ?
                            </button>
                        </h2>
                        <div id="a2" class="accordion-collapse collapse" aria-labelledby="q2" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Si tu as choisi la validation par carte etudiante, le compte est en attente
                                de moderation admin avant activation. Tant que le statut reste "pending",
                                la connexion est refusee automatiquement.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q3">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3" aria-expanded="false" aria-controls="a3">
                                Quels fichiers sont acceptes pour un depot ?
                            </button>
                        </h2>
                        <div id="a3" class="accordion-collapse collapse" aria-labelledby="q3" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Formats autorises: PDF, JPG, PNG. Taille maximale: 10 Mo.
                                Les documents sont moderes avant publication pour garantir la qualite et la conformite.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q4">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4" aria-expanded="false" aria-controls="a4">
                                Comment recuperer mon mot de passe ?
                            </button>
                        </h2>
                        <div id="a4" class="accordion-collapse collapse" aria-labelledby="q4" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Va sur <a href="forgot-password.php">Mot de passe oublie</a> pour lancer une reinitialisation.
                                Suis ensuite le lien recu (mode developpement: lien affiche a l'ecran).
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q5">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a5" aria-expanded="false" aria-controls="a5">
                                Comment contacter l'administration ?
                            </button>
                        </h2>
                        <div id="a5" class="accordion-collapse collapse" aria-labelledby="q5" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Tu peux utiliser les contacts institutionnels affiches en haut et en bas du site:
                                <strong>contact@emsp.int</strong>.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q6">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a6" aria-expanded="false" aria-controls="a6">
                                Pourquoi mon document n'apparait pas dans la bibliotheque ?
                            </button>
                        </h2>
                        <div id="a6" class="accordion-collapse collapse" aria-labelledby="q6" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Apres depot, le document passe en statut "pending". Il devient visible seulement
                                apres validation par un administrateur ou moderateur.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q7">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a7" aria-expanded="false" aria-controls="a7">
                                Puis-je modifier un document deja depose ?
                            </button>
                        </h2>
                        <div id="a7" class="accordion-collapse collapse" aria-labelledby="q7" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                La modification directe n'est pas prevue dans ce sprint. La bonne pratique est
                                de deposer une nouvelle version propre avec un titre explicite.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q8">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a8" aria-expanded="false" aria-controls="a8">
                                Comment fonctionnent les favoris ?
                            </button>
                        </h2>
                        <div id="a8" class="accordion-collapse collapse" aria-labelledby="q8" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Depuis la fiche d'un document, clique sur "Ajouter aux favoris". Tu retrouveras
                                ensuite la liste dans <a href="mes-favoris.php">Mes favoris</a>.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q9">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a9" aria-expanded="false" aria-controls="a9">
                                Les documents concours sont-ils publics ?
                            </button>
                        </h2>
                        <div id="a9" class="accordion-collapse collapse" aria-labelledby="q9" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Certains documents peuvent etre marques publics (par exemple concours/annales).
                                Les autres restent reserves aux comptes connectes.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="q10">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a10" aria-expanded="false" aria-controls="a10">
                                Qui peut acceder a l'administration ?
                            </button>
                        </h2>
                        <div id="a10" class="accordion-collapse collapse" aria-labelledby="q10" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Seuls les comptes avec role admin ou moderateur peuvent acceder au panneau
                                d'administration, a la moderation des comptes et des documents.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-pad soft-bg">
    <div class="container">
        <h2 class="section-title text-uppercase">Guides rapides</h2>
        <div class="row g-3">
            <div class="col-md-4">
                <article class="emsp-card h-100">
                    <h3>Demarrage</h3>
                    <p>Creer un compte, se connecter et configurer son profil etudiant.</p>
                    <a class="btn btn-sm btn-emsp-outline" href="register.php">Inscription</a>
                </article>
            </div>
            <div class="col-md-4">
                <article class="emsp-card h-100">
                    <h3>Consulter</h3>
                    <p>Rechercher par type, filiere, module et semestre dans la bibliotheque.</p>
                    <a class="btn btn-sm btn-emsp-outline" href="bibliotheque.php">Bibliotheque</a>
                </article>
            </div>
            <div class="col-md-4">
                <article class="emsp-card h-100">
                    <h3>Contribuer</h3>
                    <p>Deposer des ressources utiles, conformes et bien nommees pour ta promotion.</p>
                    <a class="btn btn-sm btn-emsp-outline" href="upload.php">Deposer un fichier</a>
                </article>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>


