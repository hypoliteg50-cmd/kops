<?php
/**
 * Traitement du formulaire de contact du site KOPS.
 *
 * Reçoit les demandes envoyées depuis index.html, les valide, puis les
 * transmet par courriel. Aucune donnée n'est stockée sur le serveur et
 * aucun service tiers n'intervient.
 */

// Aucun détail technique ne doit parvenir au visiteur en cas d'incident.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

const DESTINATAIRE   = 'contact@kops-performance.fr';
const EXPEDITEUR     = 'contact@kops-performance.fr';
const OBJET          = 'Demande de diagnostic — site KOPS';
const LONGUEUR_COURT = 200;
const LONGUEUR_LONG  = 5000;

/**
 * Nettoie une valeur reçue du formulaire.
 *
 * Les retours chariot sont retirés des champs courts : sans cela, une valeur
 * contenant une fin de ligne permettrait d'injecter des en-têtes dans le
 * courriel et de détourner le formulaire en relais de messages.
 */
function nettoyer(string $cle, int $longueur, bool $multiligne = false): string
{
    $valeur = isset($_POST[$cle]) && is_string($_POST[$cle]) ? $_POST[$cle] : '';
    $valeur = str_replace("\0", '', $valeur);
    $valeur = $multiligne
        ? preg_replace('/\R/u', "\n", $valeur)
        : preg_replace('/[\r\n\t]+/u', ' ', $valeur);
    $valeur = trim($valeur);

    return mb_substr($valeur, 0, $longueur, 'UTF-8');
}

/** Encode un en-tête selon la RFC 2047, pour que les accents s'affichent. */
function encoder_entete(string $texte): string
{
    return '=?UTF-8?B?' . base64_encode($texte) . '?=';
}

/** Affiche la page de réponse, aux couleurs du site, puis arrête le script. */
function repondre(string $titre, string $message, int $statut): void
{
    http_response_code($statut);
    header('Content-Type: text/html; charset=utf-8');
    $titre   = htmlspecialchars($titre, ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titre} — KOPS</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
<link rel="stylesheet" href="styles.css">
</head>
<body>

<header class="entete">
  <div class="conteneur entete__interieur">
    <a class="marque" href="/">
      <img src="images/kops-logo.png"
           alt="KOPS — Optimiser aujourd'hui, performer demain. Conseil, digital, impact."
           width="640" height="289">
    </a>
  </div>
</header>

<main>
  <section class="section accueil">
    <div class="conteneur">
      <h1>{$titre}</h1>
      <p class="accueil__sous-titre">{$message}</p>
      <p><a class="bouton-principal" href="/">Retour au site</a></p>
    </div>
  </section>
</main>

</body>
</html>
HTML;

    exit;
}

// Seules les soumissions du formulaire sont acceptées.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /#contact', true, 303);
    exit;
}

// Piège à robots : un champ invisible rempli signale un envoi automatisé.
// La réponse reste celle d'un envoi réussi, pour ne rien apprendre au robot.
if (trim($_POST['_gotcha'] ?? '') !== '') {
    repondre(
        'Votre demande est bien partie',
        'Nous revenons vers vous rapidement.',
        200
    );
}

$nom        = nettoyer('nom', LONGUEUR_COURT);
$entreprise = nettoyer('entreprise', LONGUEUR_COURT);
$email      = nettoyer('email', LONGUEUR_COURT);
$telephone  = nettoyer('telephone', LONGUEUR_COURT);
$objet      = nettoyer('objet', LONGUEUR_COURT);
$message    = nettoyer('message', LONGUEUR_LONG, true);

$erreurs = [];
if ($nom === '') {
    $erreurs[] = 'nom';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erreurs[] = 'e-mail';
}
if ($message === '') {
    $erreurs[] = 'message';
}

if ($erreurs !== []) {
    repondre(
        'Votre demande n\'a pas pu être envoyée',
        'Merci de vérifier les champs suivants, puis de renvoyer votre demande : '
            . implode(', ', $erreurs) . '.',
        400
    );
}

$corps = "Nouvelle demande envoyée depuis kops-performance.fr\n\n"
    . 'Nom et prénom : ' . $nom . "\n"
    . 'Entreprise : ' . ($entreprise !== '' ? $entreprise : 'non renseignée') . "\n"
    . 'E-mail : ' . $email . "\n"
    . 'Téléphone : ' . ($telephone !== '' ? $telephone : 'non renseigné') . "\n"
    . 'Objet : ' . ($objet !== '' ? $objet : 'non renseigné') . "\n\n"
    . "Message :\n" . $message . "\n";

$entetes = [
    'From: KOPS <' . EXPEDITEUR . '>',
    'Reply-To: ' . encoder_entete($nom) . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'MIME-Version: 1.0',
    'X-Mailer: site-kops',
];

$envoye = @mail(
    DESTINATAIRE,
    encoder_entete(OBJET),
    $corps,
    implode("\r\n", $entetes),
    '-f' . EXPEDITEUR
);

if (!$envoye) {
    error_log('KOPS : échec de l\'envoi du formulaire de contact.');
    repondre(
        'L\'envoi a échoué',
        'Une difficulté technique nous empêche de recevoir votre demande. '
            . 'Vous pouvez nous écrire directement à ' . DESTINATAIRE
            . ' ou nous appeler au +33 7 66 39 52 16.',
        500
    );
}

repondre(
    'Votre demande est bien partie',
    'Merci ' . $nom . '. Nous revenons vers vous rapidement à l\'adresse '
        . $email . '.',
    200
);
