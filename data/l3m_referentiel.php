<?php
// ============================================================
// Référentiel officiel ECSR — Matière > Sous-matière > Unité.
//
// Terminologie (à ne JAMAIS confondre — cause de bugs sinon) :
// - "Module" (sans autre precision, partout ailleurs sur cette plateforme :
//   l3m_seq_modules() dans l3m_branding.php, sequence-select.php,
//   espace-eleve.php/formateur.php...) = un des 11 COURS MOODLE de haut
//   niveau ("M1 – Cadre juridique et réglementaire de la conduite", etc.).
// - "Unité" (ce fichier) = l'unité la PLUS FINE du référentiel officiel
//   RNCP fourni par Sébastien (ex. "Accidentologie" = unité 1.1.1), rangée
//   sous une Sous-matière, elle-même rangée sous une Matière. Rien à voir
//   avec les 11 cours Moodle ni leurs sections internes ("Cours" au sens
//   client, voir memoire project_module_cours_terminology).
//
// Ce référentiel sert de base à la vue "Programme" (bibliothèque de
// supports qui se déverrouillent au fil des séquences, PAS un parcours
// navigable — voir doc de cadrage Séquences, partie "Structure de la
// formation et distinction entre programme et séquences") — à construire
// dans une prochaine partie.
//
// Contenu initial : capture d'écran du tableur "Matieres-modules-ECSR"
// partagée le 15/09/2026 — UNIQUEMENT la Matière 1 y était visible (jusqu'à
// la sous-matière 7, elle-même coupée à l'unité 1.7.2). Le document final
// annonce 57 unités au total — voir l3m_referentiel_stats() pour suivre
// l'avancement du remplissage.
//
// Depuis le 16/09/2026, les données vivent dans l3m_referentiel.json (pas
// dans ce fichier) et sont éditables par le formateur depuis
// referentiel-editor.php — le reste sera complété par l'équipe pédagogique
// directement dans la plateforme, au fil de l'eau, sans déploiement.
//
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/l3m_referentiel.php
// ============================================================

// Depuis le 16/09/2026 : le référentiel n'est plus figé en dur dans ce
// fichier PHP — il est stocké dans l3m_referentiel.json (même repertoire) et
// éditable par le formateur depuis referentiel-editor.php (ajout de
// matières/sous-matières/unités, édition des titres), pour ne plus dépendre
// d'un déploiement à chaque ajout de l'équipe pédagogique.
function l3m_referentiel_data_path(): string {
    return __DIR__ . '/l3m_referentiel.json';
}

function l3m_referentiel_matieres(): array {
    $path = l3m_referentiel_data_path();
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// Ecrase le referentiel complet — utilise par referentiel-editor.php apres
// modification (edition de titre, ajout de matiere/sous-matiere/unite).
function l3m_referentiel_save_matieres(array $matieres): void {
    file_put_contents(
        l3m_referentiel_data_path(),
        json_encode($matieres, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

// Nombre total d'unités attendues sur l'ensemble du référentiel, annoncé dans
// le document de cadrage des séquences ("57 thèmes") — sert uniquement à
// afficher un indicateur d'avancement du remplissage (voir
// l3m_referentiel_stats()), pas une contrainte technique.
define('L3M_REFERENTIEL_TOTAL_ANNONCE', 57);

// Aplati le référentiel en une liste simple [code => ['nom', 'sousmatiere_nom',
// 'matiere_nom', 'matiere_id', 'sousmatiere_id']] — pratique pour un menu
// déroulant (éditeur de séquence, futur écran "Programme") sans reparcourir
// la structure imbriquée à chaque fois.
function l3m_referentiel_unites_aplati(): array {
    $out = [];
    foreach (l3m_referentiel_matieres() as $mid => $matiere) {
        foreach ($matiere['sous_matieres'] as $sid => $sousmatiere) {
            foreach ($sousmatiere['unites'] as $code => $nom) {
                $out[$code] = [
                    'nom'             => $nom,
                    'matiere_id'      => $mid,
                    'matiere_nom'     => $matiere['nom'],
                    'sousmatiere_id'  => $sid,
                    'sousmatiere_nom' => $sousmatiere['nom'],
                ];
            }
        }
    }
    return $out;
}

// Une unité précise, ou null si le code n'existe pas (encore) dans le
// référentiel — ne jamais supposer qu'un code existe sans vérifier, tant que
// le référentiel est incomplet.
function l3m_referentiel_unite(string $code): ?array {
    $all = l3m_referentiel_unites_aplati();
    return $all[$code] ?? null;
}

// Indicateur d'avancement du remplissage — à afficher quelque part côté admin
// une fois la vue "Programme" construite, pour suivre visuellement ce qu'il
// reste à obtenir de l'équipe pédagogique.
function l3m_referentiel_stats(): array {
    $connues = count(l3m_referentiel_unites_aplati());
    return [
        'unites_connues'  => $connues,
        'unites_annoncees'=> L3M_REFERENTIEL_TOTAL_ANNONCE,
        'pct_complet'     => L3M_REFERENTIEL_TOTAL_ANNONCE > 0
            ? round($connues / L3M_REFERENTIEL_TOTAL_ANNONCE * 100)
            : 0,
    ];
}

// ============================================================
// "Séquences" de la section "Mes modules" (espace-eleve.php /
// espace-formateur.php) — un simple répertoire : chaque Séquence regroupe
// des "modules" librement choisis par le Responsable pédagogique parmi les
// Sous-matières et/ou Unités du référentiel ci-dessus (une Séquence peut
// piocher dans plusieurs Matières). Affichage seul, aucun contenu/QCM
// derrière — À NE PAS CONFONDRE avec le "Registre des Séquences" plus bas
// (Séquences de positionnement, Étapes avec contenu interactif/QCM,
// l3m_sequences.json) : deux systèmes distincts, deux fichiers de données
// distincts, voir doc de cadrage.
//
// Un "module" ici est une simple référence textuelle vers un nœud du
// référentiel :
// - "sm:<matiere_id>:<sousmatiere_id>" -> toute une Sous-matière
// - "u:<code>"                        -> une Unité précise
// ============================================================
function l3m_progseq_data_path(): string {
    return __DIR__ . '/l3m_modules_sequences.json';
}

function l3m_progseq_all(): array {
    $path = l3m_progseq_data_path();
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// Ecrase le registre complet — utilise par sequences-config.php apres
// creation/renommage/suppression d'une sequence ou d'un module.
function l3m_progseq_save(array $sequences): void {
    file_put_contents(
        l3m_progseq_data_path(),
        json_encode($sequences, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

// Libelle + matiere d'origine d'une reference de module, ou null si la
// reference ne correspond plus a rien (sous-matiere/unite supprimee du
// referentiel entre-temps) — l'appelant doit alors l'ignorer silencieusement
// plutot que de planter (meme logique defensive que l3m_seq_etape_unites_noms()).
// Le bouton d'action ("Commencer la sequence") reste unique par Sequence,
// mais chaque module reste cliquable pour renvoyer vers lui dans Programme
// (question du 06/10/2026) :
// - Sous-matiere : le module a lui-meme du contenu (ses Unites) -> target =
//   le module lui-meme, on affiche directement ce qu'il contient.
// - Unite : c'est une feuille sans enfant, une page-cul-de-sac -> target =
//   son PARENT (la Sous-matiere), pour l'afficher comme une carte parmi ses
//   freres plutot que sur une page vide ; 'highlight' la met en valeur.
function l3m_progseq_resolve_module(string $ref): ?array {
    $matieres = l3m_referentiel_matieres();
    if (strpos($ref, 'sm:') === 0) {
        [, $mid, $sid] = array_pad(explode(':', $ref), 3, null);
        $sm = $matieres[$mid]['sous_matieres'][$sid] ?? null;
        if (!$sm) return null;
        return [
            'nom' => $sm['nom'], 'matiere_nom' => $matieres[$mid]['nom'], 'type' => 'sousmatiere',
            'target' => "sm-$mid-$sid", 'highlight' => '',
        ];
    }
    if (strpos($ref, 'u:') === 0) {
        $code = substr($ref, 2);
        $u = l3m_referentiel_unite($code);
        if (!$u) return null;
        return [
            'nom' => $u['nom'], 'matiere_nom' => $u['matiere_nom'], 'type' => 'unite',
            'target' => "sm-{$u['matiere_id']}-{$u['sousmatiere_id']}", 'highlight' => $code,
        ];
    }
    return null;
}

// Liste des Sequences du registre QCM existant (voir "Registre des
// Sequences" plus haut, l3m_seq_sequences()) — sert au picker "Contenu du
// Programme lie" de sequences-config.php : une Sequence de "Mes modules" lie
// desormais une Sequence de Programme ENTIERE (toutes ses Parties
// s'enchainent automatiquement, voir l3m_progseq_etape_resolue()), pas une
// seule Partie figee — question du 19/10/2026 : un formateur devait "choisir
// quelle partie" au lieu de lier la Sequence entiere.
function l3m_progseq_etapes_picker(): array {
    $out = [];
    foreach (l3m_seq_sequences() as $seqid => $seq) {
        if (empty($seq['etapes'])) continue;
        $out[] = [
            'seqid' => (int)$seqid,
            // "Programme · " en prefixe : cette Sequence de positionnement
            // (voir sequence-select.php) et la Sequence de planning ("Mes
            // modules", voir sequences-config.php) partagent le meme mot
            // "Sequence" dans leur titre libre tape par le Responsable
            // pedagogique — sans ce prefixe, un formateur confondait les deux
            // dans ce menu (question du 19/10/2026).
            'label' => 'Programme · ' . ($seq['titre'] ?: 'Séquence ' . $seqid),
            'publiee' => !empty(l3m_seq_etapes_publiees_ids($seq)),
        ];
    }
    return $out;
}

// Partie a utiliser MAINTENANT, pour un eleve precis, d'une Sequence de
// Programme liee a une Sequence de "Mes modules" — la premiere Partie
// publiee non terminee (reprise), ou la premiere si tout est termine (voir
// l3m_seq_sequence_etape_a_lancer(), meme logique que sequence-select.php).
// Remplace l'ancien champ fige 'etape_etapeid' : une Sequence de "Mes
// modules" ne pointe plus qu'une Sequence de Programme entiere
// ('etape_seqid'), jamais une Partie precise.
function l3m_progseq_etape_resolue(int $prog_seqid, int $userid): ?array {
    $seq = l3m_seq_sequences()[$prog_seqid] ?? null;
    if (!$seq) return null;
    $lancer = l3m_seq_sequence_etape_a_lancer($userid, $prog_seqid, $seq);
    if (!$lancer) return null;
    return ['seqid' => $prog_seqid, 'etapeid' => $lancer['etapeid']];
}

// Statut de la Sequence de Programme liee a une Sequence de "Mes modules",
// pour le userid donne — null si aucune n'est liee, si elle a ete supprimee
// depuis, ou si elle n'a aucune Partie publiee (un eleve ne doit jamais voir
// une Partie en brouillon, meme a travers "Sequences" — meme regle que
// l3m_prog_statut_unite() dans programme.php).
// 'dbid'/'override' : meme mecanisme de deverrouillage manuel (global, pas
// par eleve) que le <select> du Responsable pedagogique dans programme.php,
// pour pouvoir le piloter aussi depuis "Sequences" (question du 02/10/2026).
// 'completed' utilise directement l3m_seq_document_deverrouille_pour() sur la
// Partie resolue — vrai seulement quand TOUTES les Parties sont terminees,
// puisque la resolution retombe sur la 1ere Partie (forcement terminee dans
// ce cas) une fois qu'il n'en reste aucune en cours (question du 02/10/2026 :
// un deblocage manuel ('open') doit aussi faire disparaitre le bouton
// "Commencer la sequence" ici, pas seulement debloquer le PDF la-bas).
function l3m_progseq_etape_status(?int $prog_seqid, int $userid): ?array {
    if (!$prog_seqid) return null;
    $resolu = l3m_progseq_etape_resolue($prog_seqid, $userid);
    if (!$resolu) return null;
    $seqid = $resolu['seqid']; $etapeid = $resolu['etapeid'];
    $etape = l3m_seq_etape($seqid, $etapeid);
    if (!$etape || !l3m_seq_etape_est_publiee($etape)) return null;
    $dbid = l3m_seq_db_id($seqid, $etapeid);
    $completed = l3m_seq_document_deverrouille_pour($userid, $dbid, $seqid, $etapeid);
    return ['seqid' => $seqid, 'etapeid' => $etapeid, 'completed' => $completed, 'dbid' => $dbid, 'override' => l3m_seq_doc_override($dbid)];
}

// Registre resolu, pret a serialiser en JSON pour l'affichage cote
// espace-eleve.php / espace-formateur.php (section "Sequences" de "Mes
// modules") — les references de modules qui ne correspondent plus a rien
// dans le referentiel sont silencieusement ignorees (voir
// l3m_progseq_resolve_module()).
//
// Chaque Sequence porte un 'type' ('etape', valeur par defaut, ou
// 'classe_virtuelle', voir sequences-config.php) qui determine son UNIQUE
// bouton d'action ('action' ci-dessous, kind 'etape' ou 'cv') — question du
// 01/10/2026 : le Responsable pedagogique choisit, par Sequence, si c'est un
// cours en distanciel classique (bouton "Faire l'etape") ou une classe
// virtuelle (lien de connexion direct, ex. Glowbl).
function l3m_progseq_for_display(int $userid): array {
    $out = [];
    $order = 1;
    foreach (l3m_progseq_all() as $sid => $seq) {
        $mods = [];
        foreach (($seq['modules'] ?? []) as $ref) {
            $m = l3m_progseq_resolve_module($ref);
            if ($m) $mods[] = ['ref' => $ref, 'nom' => $m['nom'], 'type' => $m['type'], 'target' => $m['target'], 'highlight' => $m['highlight']];
        }
        $type = $seq['type'] ?? 'etape';
        if ($type === 'classe_virtuelle') {
            // 'cv_auto' (case a cocher, voir sequences-config.php) : lien SSO
            // via la salle Glowbl assignee a CETTE Sequence (cv_cmid, voir
            // l3m_glowbl_assign_room() dans l3m_branding.php — chaque
            // Sequence a la sienne, jamais choisie/visible en tant que cours
            // Moodle) — sinon lien externe simple (cv_url), ou l'eleve se
            // connecte/cree un compte Glowbl a la main (question du
            // 05/10/2026 : plusieurs salles distinctes, pas une seule
            // partagee par toute la plateforme).
            if (!empty($seq['cv_auto']) && !empty($seq['cv_cmid'])) {
                global $CFG;
                $action = ['kind' => 'cv', 'url' => $CFG->wwwroot . '/mod/lti/view.php?id=' . (int)$seq['cv_cmid']];
            } else {
                $cv_url = trim($seq['cv_url'] ?? '');
                $action = $cv_url ? ['kind' => 'cv', 'url' => $cv_url] : null;
            }
        } else {
            $etape = l3m_progseq_etape_status($seq['etape_seqid'] ?? null, $userid);
            $action = $etape ? array_merge(['kind' => 'etape'], $etape) : null;
        }
        $out[] = [
            'id' => (int)$sid, 'order' => $order++, 'titre' => $seq['titre'] ?? '', 'modules' => $mods,
            'type' => $type, 'action' => $action, 'cv_done_modules' => $seq['cv_done_modules'] ?? [],
        ];
    }
    return $out;
}

// Options du picker de l'editeur de sequences (sequences-config.php),
// regroupees par Matiere : chaque groupe contient ses Sous-matieres ET leurs
// Unites (les deux niveaux sont selectionnables comme "module", voir
// doc de cadrage).
function l3m_progseq_picker_options(): array {
    $out = [];
    foreach (l3m_referentiel_matieres() as $mid => $matiere) {
        $items = [];
        foreach (($matiere['sous_matieres'] ?? []) as $sid => $sm) {
            $items[] = ['ref' => "sm:$mid:$sid", 'nom' => $sm['nom'], 'type' => 'sousmatiere'];
            foreach (($sm['unites'] ?? []) as $code => $nom) {
                $items[] = ['ref' => "u:$code", 'nom' => $nom, 'type' => 'unite'];
            }
        }
        $out[$mid] = ['matiere_nom' => $matiere['nom'], 'items' => $items];
    }
    return $out;
}

// ============================================================
// Registre des Séquences — LE parcours que l'élève suit réellement
// (demi-journées, synchrones ou asynchrones). À ne pas confondre avec le
// référentiel ci-dessus (Matière/Sous-matière/Unité), qui n'est qu'une
// bibliothèque de consultation — voir doc de cadrage Séquences, partie
// "Structure de la formation et distinction entre programme et séquences".
//
// Une Séquence regroupe une ou plusieurs Étapes ; chaque Étape est
// rattachée à une ou plusieurs Unités du référentiel (une même Séquence
// peut donc traverser plusieurs Unités/Sous-matières/Matières — c'est
// justement le problème que ce registre résout, voir doc_expli.pdf).
//
// Remplace l'ancien systeme "1 sequence = 1 Module plateforme" (voir
// l3m_seq_modules() dans l3m_branding.php, desormais orphelin — laisse en
// place pour ne rien casser ailleurs, mais plus utilise par le systeme de
// sequences depuis le 15/09/2026).
//
// Depuis le 16/09/2026 : comme le referentiel, les donnees vivent dans
// l3m_sequences.json (pas dans ce fichier) — la creation/edition de
// sequences/etapes se fera depuis la plateforme (Responsable pedagogique
// uniquement, voir l3m_is_ped_lead()), sans deploiement. Chaque etape porte
// un champ 'statut' : 'brouillon' (invisible des eleves, previsualisable par
// les formateurs avant publication) ou 'publiee' (visible de tous) — voir
// l3m_seq_etape_est_publiee().
// ============================================================
function l3m_seq_data_path(): string {
    return __DIR__ . '/l3m_sequences.json';
}

function l3m_seq_sequences(): array {
    $path = l3m_seq_data_path();
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// Ecrase le registre complet des sequences — utilise par le futur
// constructeur d'etapes (partie B) apres creation/edition/publication.
function l3m_seq_save_sequences(array $sequences): void {
    file_put_contents(
        l3m_seq_data_path(),
        json_encode($sequences, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

// 'brouillon' par defaut si le champ est absent (etape ancienne format ou
// mal formee) — plus sur de cacher par defaut que d'exposer par accident un
// contenu non valide aux eleves.
function l3m_seq_etape_est_publiee(array $etape): bool {
    return ($etape['statut'] ?? 'brouillon') === 'publiee';
}

// Identifiants d'etapes publiees d'une Sequence, tries par numero croissant
// — ordre d'enchainement cote eleve (voir sequence-select.php/sequence-player.php,
// une Sequence a plusieurs Etapes s'enchaine desormais automatiquement en un
// seul parcours plutot que d'afficher un bouton "Lancer" par Etape).
function l3m_seq_etapes_publiees_ids(array $seq): array {
    $etapes = $seq['etapes'] ?? [];
    $ids = array_map('intval', array_keys($etapes));
    sort($ids);
    return array_values(array_filter($ids, fn($id) => l3m_seq_etape_est_publiee($etapes[$id])));
}

// Etape suivante (numero superieur, publiee) dans la meme Sequence, ou null
// si $etapeid est la derniere etape publiee — utilise par sequence-player.php
// pour proposer "Continuer vers la partie suivante" en fin d'etape au lieu
// de s'arreter.
function l3m_seq_etape_suivante_id(int $seqid, int $etapeid): ?int {
    $sequences = l3m_seq_sequences();
    $seq = $sequences[$seqid] ?? null;
    if (!$seq) return null;
    foreach (l3m_seq_etapes_publiees_ids($seq) as $id) {
        if ($id > $etapeid) return $id;
    }
    return null;
}

// Sur quelle Etape pointer l'unique bouton de lancement d'une Sequence cote
// eleve : la premiere Etape publiee non terminee (reprise), ou la premiere
// Etape publiee si tout est termine (pour "Revoir"). Retourne null si la
// Sequence n'a aucune Etape publiee (rien a lancer).
function l3m_seq_sequence_etape_a_lancer(int $userid, int $seqid, array $seq): ?array {
    global $DB;
    $publiees = l3m_seq_etapes_publiees_ids($seq);
    if (!$publiees) return null;
    $done = 0;
    $premiere_non_finie = null;
    foreach ($publiees as $id) {
        $dbid = l3m_seq_db_id($seqid, $id);
        $complete = $DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $dbid, 'completed' => 1]);
        if ($complete) { $done++; } elseif ($premiere_non_finie === null) { $premiere_non_finie = $id; }
    }
    return [
        'etapeid'  => $premiere_non_finie ?? $publiees[0],
        'total'    => count($publiees),
        'done'     => $done,
        'terminee' => $done === count($publiees),
    ];
}

// Prochain identifiant numerique libre (max+1, ou 1 si vide) — utilise pour
// les nouvelles sequences/etapes ET pour le referentiel (voir
// referentiel-editor.php).
function l3m_seq_next_id(array $keys): int {
    $ints = array_map('intval', $keys);
    return $ints ? max($ints) + 1 : 1;
}

// Cree une sequence vide (sans etape) et retourne son id. Le Responsable
// pedagogique y ajoute ensuite des etapes via l3m_seq_create_etape().
function l3m_seq_create_sequence(string $titre): int {
    $sequences = l3m_seq_sequences();
    $seqid = l3m_seq_next_id(array_keys($sequences));
    $sequences[$seqid] = ['titre' => $titre, 'etapes' => []];
    l3m_seq_save_sequences($sequences);
    return $seqid;
}

// Cree une etape vide (statut brouillon, aucun bloc) dans une sequence
// existante — fichier de contenu + emplacement de support auto-generes.
// Retourne le nouvel etapeid, ou null si la sequence n'existe pas.
function l3m_seq_create_etape(int $seqid, string $titre, array $unites): ?int {
    $sequences = l3m_seq_sequences();
    if (!isset($sequences[$seqid])) return null;
    $etapeid = l3m_seq_next_id(array_keys($sequences[$seqid]['etapes'] ?? []));
    $contentfile  = "sequence_content_seq{$seqid}_etape{$etapeid}.json";
    $documentfile = "seq_documents/seq{$seqid}_etape{$etapeid}_support.pdf";
    $sequences[$seqid]['etapes'][$etapeid] = [
        'titre'        => $titre,
        'unites'       => array_values($unites),
        'contentfile'  => $contentfile,
        'documentfile' => $documentfile,
        'statut'       => 'brouillon',
    ];
    l3m_seq_save_sequences($sequences);
    $content = [
        'sequence_id' => l3m_seq_db_id($seqid, $etapeid),
        'titre'       => $titre,
        'blocs'       => [],
    ];
    file_put_contents(__DIR__ . '/' . $contentfile, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $etapeid;
}

// Supprime une etape (et ses fichiers contenu/support), ainsi que les
// reglages globaux qui la referencent par dbid (override de verrouillage,
// exceptions ponctuelles par eleve) pour ne pas laisser de configuration
// orpheline. Ne touche pas aux tables de progression/reponses des eleves
// (historique conserve meme si l'etape disparait). Retourne false si la
// sequence/etape n'existe pas.
function l3m_seq_delete_etape(int $seqid, int $etapeid): bool {
    $sequences = l3m_seq_sequences();
    if (!isset($sequences[$seqid]['etapes'][$etapeid])) return false;
    $etape = $sequences[$seqid]['etapes'][$etapeid];
    foreach (['contentfile', 'documentfile'] as $f) {
        if (!empty($etape[$f])) {
            $path = __DIR__ . '/' . $etape[$f];
            if (file_exists($path)) unlink($path);
        }
    }
    unset($sequences[$seqid]['etapes'][$etapeid]);
    l3m_seq_save_sequences($sequences);

    $dbid = l3m_seq_db_id($seqid, $etapeid);
    l3m_seq_doc_set_override($dbid, null);
    $all = l3m_seq_student_unlock_overrides_all();
    $changed = false;
    foreach ($all as $uid => $list) {
        $idx = array_search($dbid, $list, true);
        if ($idx !== false) {
            array_splice($list, $idx, 1);
            if ($list) { $all[$uid] = array_values($list); } else { unset($all[$uid]); }
            $changed = true;
        }
    }
    if ($changed) set_config('seq_unlock_overrides', json_encode($all), 'local_l3madmin');

    return true;
}

// Supprime une Sequence ENTIERE (toutes ses Parties, et la Sequence
// elle-meme) — le bouton "Supprimer" de chaque Partie (voir
// l3m_seq_delete_etape()) ne retirait que cette Partie, pas la Sequence qui
// la contient (question du 19/10/2026). Reutilise le meme nettoyage par
// Partie (fichiers, override global, exceptions par eleve), puis delie toute
// Sequence de "Mes modules" qui la referencait (etape_seqid) pour ne pas
// laisser de lien mort.
function l3m_seq_delete_sequence(int $seqid): bool {
    $sequences = l3m_seq_sequences();
    if (!isset($sequences[$seqid])) return false;
    foreach (array_keys($sequences[$seqid]['etapes'] ?? []) as $etapeid) {
        l3m_seq_delete_etape($seqid, $etapeid);
    }
    $sequences = l3m_seq_sequences(); // relue : l3m_seq_delete_etape() a deja sauvegarde
    unset($sequences[$seqid]);
    l3m_seq_save_sequences($sequences);

    $progseq = l3m_progseq_all();
    $changed = false;
    foreach ($progseq as $sid => $seq) {
        if ((int)($seq['etape_seqid'] ?? 0) === $seqid) {
            unset($progseq[$sid]['etape_seqid']);
            $changed = true;
        }
    }
    if ($changed) l3m_progseq_save($progseq);

    return true;
}

// ============================================================
// Constructeur de blocs (partie B) — squelettes valides pour chaque type de
// bloc/ecran supporte par sequence-player.php, generes par
// sequence-editor.php quand le Responsable pedagogique clique "+ Ajouter".
// Jamais de type invente : uniquement ceux que le lecteur sait afficher.
// ============================================================

// $n = compteur global de blocs dans l'etape (avant ajout), utilise pour
// batir une cle toujours unique ("expo_3") sans avoir a deviner un
// prochain numero par type.
function l3m_seq_new_bloc(string $type, int $n): array {
    $cle = $type . '_' . $n;
    switch ($type) {
        case 'ouverture':
            return ['cle' => $cle, 'type' => 'ouverture', 'titre' => '', 'accroche' => '', 'objectifs' => ['']];
        case 'expo':
            return ['cle' => $cle, 'type' => 'expo', 'titre_partie' => '', 'ecrans' => []];
        case 'transition':
            return ['cle' => $cle, 'type' => 'transition', 'texte' => '', 'bouton' => 'Continuer'];
        case 'qcm_bloc':
            return ['cle' => $cle, 'type' => 'qcm_bloc', 'statut' => 'note', 'seuil_bon_sur_total' => 1, 'seuil_pct' => 70, 'questions' => []];
        case 'collecte_brute':
            return ['cle' => $cle, 'type' => 'collecte_brute', 'id' => $cle, 'champs' => []];
        case 'cloture':
            return ['cle' => $cle, 'type' => 'cloture', 'texte' => '', 'document' => ['nom_affiche' => '']];
    }
    return [];
}

// $blocCle = cle du bloc parent, $n = compteur d'ecrans dans ce bloc avant
// ajout — sert a batir un id d'ecran unique ("expo_3_2").
function l3m_seq_new_ecran(string $sstype, string $blocCle, int $n): array {
    $id = $blocCle . '_' . $n;
    switch ($sstype) {
        case 'amorce':
            return ['id' => $id, 'type' => 'amorce', 'question' => ''];
        case 'texte_seul':
            return ['id' => $id, 'type' => 'texte_seul', 'texte' => ''];
        case 'synthese':
            return ['id' => $id, 'type' => 'synthese', 'texte' => ''];
        case 'texte_question':
            return ['id' => $id, 'type' => 'texte_question', 'id_question' => $id, 'question' => '', 'reponse_reference' => ''];
        case 'texte_question_double':
            return ['id' => $id, 'type' => 'texte_question_double', 'questions' => [
                ['id_question' => $id . 'a', 'question' => '', 'reponse_reference' => ''],
                ['id_question' => $id . 'b', 'question' => '', 'reponse_reference' => ''],
            ]];
        case 'amorces_multiples':
            return ['id' => $id, 'type' => 'amorces_multiples', 'questions' => [
                ['id_question' => $id . 'a', 'question' => ''],
                ['id_question' => $id . 'b', 'question' => ''],
            ]];
    }
    return [];
}

function l3m_seq_new_qcm_question(string $blocCle, int $n): array {
    $id = $blocCle . '_' . $n;
    return ['id' => $id, 'question' => '', 'options' => [
        ['texte' => '', 'correcte' => false, 'explication' => ''],
        ['texte' => '', 'correcte' => false, 'explication' => ''],
    ]];
}

function l3m_seq_new_qcm_option(): array {
    return ['texte' => '', 'correcte' => false, 'explication' => ''];
}

function l3m_seq_new_champ(int $n): array {
    return ['id_champ' => 'champ' . $n, 'consigne' => ''];
}

// Verifie qu'un contenu d'etape est publiable (bloc/QCM bien formes) — pour
// eviter d'exposer aux eleves une etape structurellement cassee. N'exige pas
// que le TEXTE soit rempli (une etape peut etre publiee avec des placeholders
// vides, c'est au Responsable pedagogique d'en juger), seulement que la
// structure soit coherente.
function l3m_seq_etape_erreurs_publication(array $content): array {
    $erreurs = [];
    $blocs = $content['blocs'] ?? [];
    if (!$blocs) { $erreurs[] = "Aucun bloc — ajoutez au moins un bloc de contenu."; return $erreurs; }
    foreach ($blocs as $i => $b) {
        $label = 'Bloc ' . ($i + 1) . ' (' . ($b['type'] ?? '?') . ')';
        if (($b['type'] ?? '') === 'qcm_bloc') {
            $qs = $b['questions'] ?? [];
            if (!$qs) { $erreurs[] = "$label : aucune question."; continue; }
            foreach ($qs as $qi => $q) {
                $opts = $q['options'] ?? [];
                if (count($opts) < 2) $erreurs[] = "$label, question " . ($qi + 1) . " : au moins 2 options requises.";
                if (!array_filter($opts, fn($o) => !empty($o['correcte']))) $erreurs[] = "$label, question " . ($qi + 1) . " : aucune bonne réponse cochée.";
            }
            $seuil = (int)($b['seuil_bon_sur_total'] ?? 0);
            if ($seuil < 1 || $seuil > count($qs)) $erreurs[] = "$label : seuil requis incohérent (doit être entre 1 et " . count($qs) . ").";
        } elseif (($b['type'] ?? '') === 'expo' && empty($b['ecrans'])) {
            $erreurs[] = "$label : aucun écran.";
        } elseif (($b['type'] ?? '') === 'collecte_brute' && empty($b['champs'])) {
            $erreurs[] = "$label : aucun champ.";
        }
    }
    return $erreurs;
}

// Une étape précise, ou null si la séquence/étape n'existe pas (encore).
function l3m_seq_etape(int $seqid, int $etapeid): ?array {
    $seqs = l3m_seq_sequences();
    return $seqs[$seqid]['etapes'][$etapeid] ?? null;
}

// Chemin du fichier de contenu d'une étape, ou null si l'étape n'existe pas.
function l3m_seq_etape_content_path(int $seqid, int $etapeid): ?string {
    $etape = l3m_seq_etape($seqid, $etapeid);
    if (!$etape) return null;
    return __DIR__ . '/' . $etape['contentfile'];
}

// Texte brut (sans mise en forme) du contenu pedagogique d'une etape — lit
// son fichier JSON d'ecrans/QCM et concatene toutes les chaines de texte
// utiles (questions, accroches, objectifs...), en ignorant les champs
// techniques (cle, type, id, statut...). Sert a ancrer le chat IA "Demander
// a Lia" cote formateur sur le vrai contenu d'une Sequence, remplace
// l'ancienne extraction depuis le contenu HTML des pages Moodle d'un cours
// (supprimee avec "Corrections en attente"/la visionneuse de cours, question
// du 06/10/2026 "on a plus les cours moodle").
function l3m_seq_etape_texte_brut(int $seqid, int $etapeid): string {
    $path = l3m_seq_etape_content_path($seqid, $etapeid);
    if (!$path || !file_exists($path)) return '';
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return '';
    $skip = ['cle', 'type', 'id', 'statut', 'sequence_id', 'unites', 'contentfile',
              'documentfile', '_avertissement', 'seuil_bon_sur_total'];
    $out = [];
    $walk = function($node) use (&$walk, &$out, $skip) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k) && in_array($k, $skip, true)) continue;
                $walk($v);
            }
        } elseif (is_string($node)) {
            $t = trim($node);
            if (mb_strlen($t) > 2) $out[] = $t;
        }
    };
    $walk($data);
    $txt = trim(preg_replace('/\s+/u', ' ', implode(' ', $out)));
    return mb_strlen($txt) > 6000 ? mb_substr($txt, 0, 6000) : $txt;
}

// Contexte (titre + texte pedagogique) d'une Sequence de "Mes modules", pour
// ancrer le chat IA "Demander a Lia" cote formateur — remplace le contexte
// base sur le contenu d'un cours Moodle. Null si la Sequence n'existe pas.
function l3m_progseq_contexte_ia(int $seqid): ?array {
    $seq = l3m_progseq_all()[$seqid] ?? null;
    if (!$seq) return null;
    $titre = $seq['titre'] ?? ('Séquence ' . $seqid);
    $modules_noms = [];
    foreach ($seq['modules'] ?? [] as $ref) {
        $m = l3m_progseq_resolve_module($ref);
        if ($m) $modules_noms[] = $m['nom'];
    }
    $parts = [];
    if ($modules_noms) $parts[] = 'Modules de cette séquence : ' . implode(', ', $modules_noms) . '.';
    $type = $seq['type'] ?? 'etape';
    if ($type === 'etape' && !empty($seq['etape_seqid'])) {
        // Concatene TOUTES les Parties publiees de la Sequence de Programme
        // liee (pas seulement la 1ere) : ce contexte est global (pas par
        // eleve), donc pas de notion de "partie en cours" ici.
        $prog_seq = l3m_seq_sequences()[(int)$seq['etape_seqid']] ?? null;
        if ($prog_seq) {
            $txts = [];
            foreach (l3m_seq_etapes_publiees_ids($prog_seq) as $eid) {
                $t = l3m_seq_etape_texte_brut((int)$seq['etape_seqid'], $eid);
                if ($t !== '') $txts[] = $t;
            }
            if ($txts) $parts[] = "Contenu pédagogique de la séquence liée :\n" . implode("\n\n", $txts);
        }
    } elseif ($type === 'classe_virtuelle') {
        $parts[] = "C'est une classe virtuelle (visioconférence en direct) : pas de contenu écrit associé.";
    }
    return ['titre' => $titre, 'texte' => trim(implode("\n\n", $parts))];
}

// ── Document PDF directement rattache a une Unite, independant de toute
// Etape QCM — la grande majorite des Unites du referentiel n'ont pas (encore)
// d'Etape de positionnement derriere elles (voir "Pas encore programmee"
// dans Programme), ce qui empechait jusqu'ici tout depot de support. Permet
// au Responsable pedagogique d'uploader directement un PDF pour une Unite
// depuis Programme, sans creer d'Etape complete. Le verrouillage reste gere
// par le meme mecanisme d'override que les Etapes (l3m_seq_doc_override),
// sur un dbid dedie — question du 07/10/2026. Format du dbid compatible
// PARAM_ALPHANUMEXT (pas de ':' ni de '.', voir le handler set_override
// existant dans programme.php).
function l3m_unite_doc_dir(): string {
    return __DIR__ . '/unite_documents';
}

function l3m_unite_doc_filename(string $code): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $code) . '.pdf';
}

function l3m_unite_doc_path(string $code): string {
    return l3m_unite_doc_dir() . '/' . l3m_unite_doc_filename($code);
}

function l3m_unite_doc_disponible(string $code): bool {
    return is_file(l3m_unite_doc_path($code));
}

function l3m_unite_doc_dbid(string $code): string {
    return 'unite_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $code);
}

// Identifiant utilisé cote base de donnees (colonne "sequenceid" des tables
// local_l3madmin_seq_state / local_l3madmin_seq_answers) — ces tables
// stockent deja une simple chaine libre (voir historique "mod_<id>"), donc
// aucune migration de schema n'est necessaire pour ce nouveau format.
function l3m_seq_db_id(int $seqid, int $etapeid): string {
    return "seq{$seqid}_etape{$etapeid}";
}

// Noms des unites couvertes par une etape, prets a afficher (ex. pour
// l'ecran de selection) — filtre silencieusement les codes qui n'existeraient
// pas (encore) dans le referentiel plutot que de planter.
function l3m_seq_etape_unites_noms(array $etape): array {
    $noms = [];
    foreach ($etape['unites'] ?? [] as $code) {
        $u = l3m_referentiel_unite($code);
        if ($u) $noms[] = $u['nom'];
    }
    return $noms;
}

// ============================================================
// Support pré-rédigé par étape (mécanisme de déverrouillage, voir
// doc_expli.pdf) — remplace l'ancien export PDF généré depuis les réponses
// de l'élève. Un seul fichier, identique pour tous les élèves, qui devient
// téléchargeable une fois l'étape terminée (voir sequence-player.php,
// action "download_document").
// ============================================================

// Chemin absolu du support d'une étape, ou null si l'étape n'en définit pas.
// Ne garantit PAS que le fichier existe reellement sur le disque — voir
// l3m_seq_etape_document_disponible().
function l3m_seq_etape_document_path(int $seqid, int $etapeid): ?string {
    $etape = l3m_seq_etape($seqid, $etapeid);
    if (!$etape || empty($etape['documentfile'])) return null;
    return __DIR__ . '/' . $etape['documentfile'];
}

// Le support est-il effectivement present sur le disque ? Tant que l'equipe
// pedagogique n'a pas fourni le vrai fichier, on l'affiche comme "en
// preparation" plutot que de planter sur un fichier manquant.
function l3m_seq_etape_document_disponible(int $seqid, int $etapeid): bool {
    $path = l3m_seq_etape_document_path($seqid, $etapeid);
    return $path !== null && file_exists($path);
}

// ============================================================
// Verrouillage/deverrouillage manuel du support par le formateur — vient
// se superposer au deverrouillage automatique (etape terminee). Utile pour
// publier un support en avance, ou au contraire le retirer temporairement
// (ex. erreur a corriger) sans toucher a la progression des eleves.
// Stocke dans local_l3madmin_seq_doc_override (une ligne par etape/document,
// PAS par eleve — la decision s'applique a tout le monde).
// ============================================================

// null = automatique (suit la progression), 'open' = force disponible pour
// tous, 'locked' = force verrouille pour tous (meme si termine).
function l3m_seq_doc_override(string $sequenceid): ?string {
    global $DB;
    $row = $DB->get_record('local_l3madmin_seq_doc_override', ['sequenceid' => $sequenceid]);
    return $row ? $row->override : null;
}

// $value = 'open' | 'locked' | null (null = retour au mode automatique).
function l3m_seq_doc_set_override(string $sequenceid, ?string $value): void {
    global $DB;
    $row = $DB->get_record('local_l3madmin_seq_doc_override', ['sequenceid' => $sequenceid]);
    if ($value === null) {
        if ($row) $DB->delete_records('local_l3madmin_seq_doc_override', ['id' => $row->id]);
        return;
    }
    if ($row) {
        $row->override = $value;
        $row->timemodified = time();
        $DB->update_record('local_l3madmin_seq_doc_override', $row);
    } else {
        $DB->insert_record('local_l3madmin_seq_doc_override', (object)[
            'sequenceid' => $sequenceid, 'override' => $value, 'timemodified' => time(),
        ]);
    }
}

// Le support est-il reellement telechargeable MAINTENANT pour cet
// utilisateur ? Combine : fichier present + override eventuel + a defaut,
// progression normale (etape terminee) — seule source de verite utilisee a
// la fois par sequence-player.php (telechargement) et programme.php
// (affichage du statut).
function l3m_seq_document_deverrouille_pour(int $userid, string $sequenceid, int $seqid, int $etapeid): bool {
    if (!l3m_seq_etape_document_disponible($seqid, $etapeid)) return false;
    $override = l3m_seq_doc_override($sequenceid);
    if ($override === 'locked') return false;
    if ($override === 'open') return true;
    global $DB;
    if ($DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $sequenceid, 'completed' => 1])) {
        return true;
    }
    return l3m_seq_student_unlock_override($userid, $sequenceid);
}

// ── Deblocage ponctuel d'une Sequence pour un eleve precis (exception
// manuelle, Responsable pedagogique) — remplace le 07/10/2026 l'ancien
// "deblocage ponctuel de module" base sur les cours Moodle (verrouillage
// sequentiel par completion de cours, devenu sans objet cote Sequences).
// Stocke separement de la vraie completion (local_l3madmin_seq_state) pour
// ne jamais fausser la progression reelle affichee ailleurs
// (l3m_progseq_global_pct) : seul l'ACCES (le support telechargeable) est
// deverrouille par cette exception, pas la completion de l'etape elle-meme —
// meme principe que l'ancien "module_unlock_overrides", juste reporte sur un
// dbid de Sequence plutot qu'un id de cours Moodle.
function l3m_seq_student_unlock_overrides_all(): array {
    return json_decode(get_config('local_l3madmin', 'seq_unlock_overrides') ?: '{}', true) ?: [];
}

function l3m_seq_student_unlock_override(int $userid, string $sequenceid): bool {
    $all = l3m_seq_student_unlock_overrides_all();
    return in_array($sequenceid, $all[$userid] ?? [], true);
}

function l3m_seq_student_unlock_toggle(int $userid, string $sequenceid, bool $enable): void {
    $all = l3m_seq_student_unlock_overrides_all();
    $list = $all[$userid] ?? [];
    $idx = array_search($sequenceid, $list, true);
    if ($enable && $idx === false) {
        $list[] = $sequenceid;
    } elseif (!$enable && $idx !== false) {
        array_splice($list, $idx, 1);
    }
    if ($list) { $all[$userid] = array_values($list); } else { unset($all[$userid]); }
    set_config('seq_unlock_overrides', json_encode($all), 'local_l3madmin');
}

// Liste des (seqid, etapeid) dont l'etape couvre une unite donnee du
// referentiel — sert a la vue "Programme" pour savoir quelle(s) etape(s)
// deverrouillent le support d'une unite. Une unite peut etre couverte par
// plusieurs etapes (rare mais pas exclu par la structure).
function l3m_referentiel_unite_etapes(string $code): array {
    $out = [];
    foreach (l3m_seq_sequences() as $seqid => $seq) {
        foreach ($seq['etapes'] as $etapeid => $etape) {
            if (in_array($code, $etape['unites'] ?? [], true)) {
                $out[] = ['seqid' => $seqid, 'etapeid' => $etapeid, 'etape' => $etape, 'sequence' => $seq];
            }
        }
    }
    return $out;
}

// Toutes les Sequences du registre "Mes modules" (l3m_modules_sequences.json,
// PAS le registre des Etapes QCM ci-dessus) qui listent directement l'Unite
// $code comme module — sert a l3m_prog_statut_unite() dans programme.php
// pour qu'une Unite listee dans PLUSIEURS Sequences n'ouvre son support
// qu'une fois TOUTES ces Sequences satisfaites (ET, pas OU) — question du
// 06/10/2026 : "si Accidentologie est dans Sequence 1 et Sequence 2, il faut
// finir les deux". Deux "kind" de Sequence, deux facons d'etre "satisfaite" :
// - 'etape' (cours en distanciel) : son Etape liee est terminee (par eleve,
//   ou debloquee manuellement) — voir la boucle dans l3m_prog_statut_unite().
// - 'cv' (classe virtuelle) : pas de suivi automatique possible (Glowbl ne
//   remonte pas la presence), donc coche a la main par le Responsable
//   pedagogique, module par module (cv_done_modules — la liste des refs
//   effectivement abordees pendant la seance, PAS un "tout ou rien" global :
//   question du 06/10/2026, "peut-etre il ne va pas debloquer tous les
//   modules, plutot choisir lequel"). Voir la checklist par module dans
//   espace-formateur.php.
function l3m_progseq_sequences_for_unite(string $code): array {
    $out = [];
    $ref = "u:$code";
    foreach (l3m_progseq_all() as $sid => $seq) {
        if (!in_array($ref, $seq['modules'] ?? [], true)) continue;
        $type = $seq['type'] ?? 'etape';
        if ($type === 'etape') {
            if (empty($seq['etape_seqid'])) continue;
            $out[] = ['sid' => (int)$sid, 'kind' => 'etape', 'etape_seqid' => (int)$seq['etape_seqid']];
        } elseif ($type === 'classe_virtuelle') {
            $done_modules = $seq['cv_done_modules'] ?? [];
            $out[] = ['sid' => (int)$sid, 'kind' => 'cv', 'cv_done' => in_array($ref, $done_modules, true)];
        }
    }
    return $out;
}

// Statut de deverrouillage d'une Unite (document pre-redige : disponible,
// verrouille, pas encore programme...) pour un utilisateur donne. Deplacee
// ici depuis programme.php le 06/10/2026 pour etre reutilisable par Parcours
// (espace-eleve.php, progression par bloc/sous-matiere) sans dupliquer la
// logique — voir l3m_progseq_sm_stats() juste en dessous.
function l3m_prog_statut_unite(string $code, int $userid, bool $voir_brouillons = false): array {
    $seqs = l3m_progseq_sequences_for_unite($code);
    $seqs_etape = array_values(array_filter($seqs, fn($s) => $s['kind'] === 'etape'));
    $seqs_cv = array_values(array_filter($seqs, fn($s) => $s['kind'] === 'cv'));

    if ($seqs_etape) {
        $valides = [];
        foreach ($seqs_etape as $s) {
            // Resolu par eleve : la Partie "en cours" de la Sequence de
            // Programme liee (premiere non terminee, ou la 1ere si tout est
            // fait) — plus une Partie figee par Sequence de "Mes modules"
            // depuis que toutes les Parties s'enchainent automatiquement.
            $resolu = l3m_progseq_etape_resolue($s['etape_seqid'], $userid);
            if (!$resolu) continue;
            $etape = l3m_seq_etape($resolu['seqid'], $resolu['etapeid']);
            if (!$etape) continue;
            if (!$voir_brouillons && !l3m_seq_etape_est_publiee($etape)) continue;
            $valides[] = ['resolu' => $resolu, 'etape' => $etape];
        }
        if (!$valides) {
            return ['etat' => 'aucune', 'label' => 'Pas encore programmée'];
        }
        $canon = null;
        foreach ($valides as $v) { if (l3m_seq_etape_est_publiee($v['etape'])) { $canon = $v; break; } }
        if (!$canon) {
            return ['etat' => 'en_preparation', 'label' => '🚧 Brouillon (non publiée)'];
        }
        $seqid = $canon['resolu']['seqid']; $etapeid = $canon['resolu']['etapeid'];
        $dbid = l3m_seq_db_id($seqid, $etapeid);
        $dispo = l3m_seq_etape_document_disponible($seqid, $etapeid);
        $override = l3m_seq_doc_override($dbid);
        $base = ['seqid' => $seqid, 'etapeid' => $etapeid, 'dbid' => $dbid, 'override' => $override];

        if (!$dispo) {
            global $DB;
            $termine = $DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $dbid, 'completed' => 1]);
            $label = $termine ? 'Étape terminée — support en préparation' : 'Support en préparation';
            return array_merge($base, ['etat' => 'en_preparation', 'label' => $label]);
        }
        if ($override === 'locked') {
            return array_merge($base, ['etat' => 'verrouille', 'label' => 'Verrouillé par le formateur']);
        }

        global $DB;
        $toutes_ok = true;
        foreach ($valides as $v) {
            $sid2 = $v['resolu']['seqid']; $eid2 = $v['resolu']['etapeid'];
            $dbid2 = l3m_seq_db_id($sid2, $eid2);
            $ov2 = l3m_seq_doc_override($dbid2);
            if ($ov2 === 'locked') { $toutes_ok = false; break; }
            if ($ov2 === 'open') continue;
            if (!$DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $dbid2, 'completed' => 1])
                && !l3m_seq_student_unlock_override($userid, $dbid2)) {
                $toutes_ok = false; break;
            }
        }
        if ($toutes_ok) {
            foreach ($seqs_cv as $cv) {
                if (empty($cv['cv_done'])) { $toutes_ok = false; break; }
            }
        }
        if ($toutes_ok) {
            return array_merge($base, ['etat' => 'deverrouille', 'label' => $override === 'open' ? 'Débloqué par le formateur' : 'Support disponible']);
        }
        return array_merge($base, ['etat' => 'verrouille', 'label' => 'À débloquer']);
    }

    // ── Ancien mecanisme : aucune Sequence ne liste cette Unite, on retombe
    //    sur la couverture directe par une Etape QCM. ──
    $etapes = l3m_referentiel_unite_etapes($code);
    if (!$voir_brouillons) {
        $etapes = array_values(array_filter($etapes, fn($e) => l3m_seq_etape_est_publiee($e['etape'])));
    }
    if (!$etapes) {
        // Aucune Etape ne couvre cette Unite : on retombe sur un document
        // depose directement (voir l3m_unite_doc_*() ci-dessus) — permet au
        // Responsable pedagogique de fournir un support sans passer par une
        // Etape QCM complete.
        if (!l3m_unite_doc_disponible($code)) {
            return ['etat' => 'aucune', 'label' => 'Pas encore programmée', 'direct' => true];
        }
        $dbid_u = l3m_unite_doc_dbid($code);
        $override_u = l3m_seq_doc_override($dbid_u);
        $base_u = ['dbid' => $dbid_u, 'direct' => true, 'override' => $override_u];
        if ($override_u === 'open') {
            return array_merge($base_u, ['etat' => 'deverrouille', 'label' => 'Débloqué par le formateur']);
        }
        return array_merge($base_u, ['etat' => 'verrouille', 'label' => 'À débloquer']);
    }
    $e = $etapes[0]; // une unite est en pratique couverte par une seule etape pour l'instant
    $seqid = $e['seqid']; $etapeid = $e['etapeid'];
    $dbid = l3m_seq_db_id($seqid, $etapeid);
    if (!l3m_seq_etape_est_publiee($e['etape'])) {
        return ['etat' => 'en_preparation', 'label' => '🚧 Brouillon (non publiée)'];
    }
    $dispo = l3m_seq_etape_document_disponible($seqid, $etapeid);
    $override = l3m_seq_doc_override($dbid);
    $base = ['seqid' => $seqid, 'etapeid' => $etapeid, 'dbid' => $dbid, 'override' => $override];

    if (!$dispo) {
        global $DB;
        $termine = $DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $dbid, 'completed' => 1]);
        $label = $termine ? 'Étape terminée — support en préparation' : 'Support en préparation';
        return array_merge($base, ['etat' => 'en_preparation', 'label' => $label]);
    }
    if ($override === 'locked') {
        return array_merge($base, ['etat' => 'verrouille', 'label' => 'Verrouillé par le formateur']);
    }
    if ($override === 'open') {
        return array_merge($base, ['etat' => 'deverrouille', 'label' => 'Débloqué par le formateur']);
    }
    if (l3m_seq_document_deverrouille_pour($userid, $dbid, $seqid, $etapeid)) {
        return array_merge($base, ['etat' => 'deverrouille', 'label' => 'Support disponible']);
    }
    return array_merge($base, ['etat' => 'verrouille', 'label' => 'À débloquer']);
}

// ── Acces formateur par Sequence (table l3m_seq_formateur_access, voir son
// auto-creation dans espace-formateur.php) — remplace le 07/10/2026
// l'ancienne matrice par cours Moodle (role editingteacher/teacher), les
// Sequences n'ayant pas d'equivalent Moodle natif. "view" = la Sequence
// apparait pour ce formateur dans "Programme" (lecture seule) ; "edit" = en
// plus, il peut utiliser les controles de deverrouillage/upload
// habituellement reserves au Responsable pedagogique, sur cette Sequence
// precisement (pas sequences-config.php, toujours reserve au Responsable
// pedagogique). ──
function l3m_seq_formateur_access_all(): array {
    global $DB;
    $rows = $DB->get_records('l3m_seq_formateur_access');
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r->userid][(string)$r->seqid] = $r->level;
    }
    return $out;
}

function l3m_seq_formateur_access_level(int $userid, $seqid): string {
    global $DB;
    $row = $DB->get_record('l3m_seq_formateur_access', ['userid' => $userid, 'seqid' => (string)$seqid]);
    return $row ? $row->level : 'none';
}

// Un formateur avec acces "edit" sur une Sequence de type "etape" peut
// deverrouiller/verrouiller manuellement le dbid de l'Etape qu'elle porte
// (meme <select> que le Responsable pedagogique, voir programme.php) —
// cherche parmi toutes les Sequences laquelle (le cas echeant) porte ce dbid
// et si ce formateur y a un acces "edit".
function l3m_seq_formateur_can_edit_dbid(int $userid, string $dbid): bool {
    foreach (l3m_progseq_all() as $sid => $seq) {
        if (($seq['type'] ?? 'etape') !== 'etape') continue;
        if (empty($seq['etape_seqid'])) continue;
        // Une Sequence de "Mes modules" lie une Sequence de Programme ENTIERE
        // (toutes ses Parties, voir l3m_progseq_etape_resolue()) : on cherche
        // le dbid parmi TOUTES ses Parties, plus une seule figee.
        $prog_seq = l3m_seq_sequences()[(int)$seq['etape_seqid']] ?? null;
        if (!$prog_seq) continue;
        $porte_ce_dbid = false;
        foreach (array_keys($prog_seq['etapes'] ?? []) as $eid) {
            if (l3m_seq_db_id((int)$seq['etape_seqid'], (int)$eid) === $dbid) { $porte_ce_dbid = true; break; }
        }
        if (!$porte_ce_dbid) continue;
        if (l3m_seq_formateur_access_level($userid, $sid) === 'edit') return true;
    }
    return false;
}

// Libelle lisible d'un dbid de support ("seq{X}_etape{Y}" ou "unite_...",
// voir l3m_seq_db_id()/l3m_unite_doc_dbid()) — pour que les notifications
// ("Support débloqué") disent QUEL support plutot qu'un message generique,
// question du 09/10/2026 "il faut qu'il donne plus de détail". Le format
// "unite_..." n'est pas reversible directement (caracteres remplaces par des
// underscores), on retrouve donc le code en recalculant le dbid de chaque
// Unite du referentiel jusqu'a trouver celle qui correspond.
function l3m_resolve_dbid_label(string $dbid): string {
    if (preg_match('/^seq(\d+)_etape(\d+)$/', $dbid, $m)) {
        $etape = l3m_seq_etape((int)$m[1], (int)$m[2]);
        if ($etape) return $etape['titre'] ?? 'ce support';
    }
    if (strpos($dbid, 'unite_') === 0) {
        foreach (l3m_referentiel_unites_aplati() as $code => $u) {
            if (l3m_unite_doc_dbid($code) === $dbid) return $u['nom'];
        }
    }
    return 'ce support';
}

function l3m_seq_formateur_set_access(int $userid, $seqid, string $level): void {
    global $DB;
    $existing = $DB->get_record('l3m_seq_formateur_access', ['userid' => $userid, 'seqid' => (string)$seqid]);
    if ($level === 'none') {
        if ($existing) $DB->delete_records('l3m_seq_formateur_access', ['id' => $existing->id]);
        return;
    }
    if ($existing) {
        $existing->level = $level;
        $existing->timemodified = time();
        $DB->update_record('l3m_seq_formateur_access', $existing);
    } else {
        $DB->insert_record('l3m_seq_formateur_access', (object)[
            'userid' => $userid, 'seqid' => (string)$seqid, 'level' => $level, 'timemodified' => time(),
        ]);
    }
}

// Progression (unites deverrouillees / total) d'une Sous-matiere du
// referentiel, pour la page Parcours (espace-eleve.php) : remplace
// l'ancienne barre de progression basee sur l'avancement Moodle du cours
// associe (supprimee en meme temps que "Mes modules", question du 06/10/2026
// "c'est toujours 44%" — la frise Parcours affichait encore un pourcentage
// Moodle alors que Programme/Sequences est maintenant la seule source de
// verite sur la progression).
function l3m_progseq_sm_stats(int $mid, int $sid, int $userid): array {
    $matieres = l3m_referentiel_matieres();
    $sm = $matieres[$mid]['sous_matieres'][$sid] ?? null;
    if (!$sm) return ['done' => 0, 'total' => 0, 'pct' => 0];
    $total = 0; $done = 0;
    foreach (array_keys($sm['unites'] ?? []) as $code) {
        $total++;
        $st = l3m_prog_statut_unite($code, $userid);
        if ($st['etat'] === 'deverrouille') $done++;
    }
    return ['done' => $done, 'total' => $total, 'pct' => $total > 0 ? (int)round($done / $total * 100) : 0];
}

// Progression globale de l'eleve sur le referentiel (Programme/Sequences) :
// proportion des Unites dont l'Etape couvrante (QCM/exposition) est
// terminee, sur l'ensemble des Unites existantes dans le referentiel —
// remplace l'ancienne progression basee sur les 11 cours Moodle (section
// "Mes modules" retiree cote eleve, voir espace-eleve.php), question du
// 01/10/2026. Compte la completion reelle de l'eleve (local_l3madmin_seq_state),
// pas la disponibilite du PDF (qui depend en plus d'un depot par l'equipe
// pedagogique, voir l3m_prog_statut_unite() dans programme.php) : une Etape
// terminee compte pour la progression meme si son support n'est pas encore
// en ligne.
function l3m_progseq_global_pct(int $userid): int {
    global $DB;
    $total = 0;
    $done = 0;
    foreach (array_keys(l3m_referentiel_unites_aplati()) as $code) {
        $total++;
        $etapes = array_values(array_filter(
            l3m_referentiel_unite_etapes($code),
            fn($e) => l3m_seq_etape_est_publiee($e['etape'])
        ));
        if (!$etapes) continue;
        $e = $etapes[0]; // une unite est en pratique couverte par une seule etape pour l'instant
        $dbid = l3m_seq_db_id($e['seqid'], $e['etapeid']);
        if ($DB->record_exists('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $dbid, 'completed' => 1])) {
            $done++;
        }
    }
    return $total > 0 ? (int)round($done / $total * 100) : 0;
}
