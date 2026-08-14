<?php
// ============================================================
// Moteur Lia — calcul de charge et arbitrage ECSR (35 semaines)
// Source unique partagée par admin-lba.php et espace-formateur.php.
// Portage PHP du moteur JS historique de admin-lba.php (liaComputePlan
// et fonctions associées) — ne pas dupliquer cette logique ailleurs.
// Déploiement : SFTP → /var/data/www/public/lia_engine.php
// ============================================================

function lia_blocs(): array {
    return [
        ['n' => "Politique de la sécurité routière",    'rem' => 65,  'v' => ['B' => 30, 'J' => 10]],
        ['n' => "Cadre de l'activité ECSR",             'rem' => 60,  'v' => []],
        ['n' => "L'automobile",                         'rem' => 60,  'v' => ['T' => 20]],
        ['n' => "Analyse de la tâche de conduite",      'rem' => 30,  'v' => ['T' => 30]],
        ['n' => "Physiologie du conducteur",            'rem' => 30,  'v' => ['P' => 10]],
        ['n' => "Psychologie du conducteur",            'rem' => 10,  'v' => []],
        ['n' => "Notions pédagogiques",                 'rem' => 40,  'v' => ['B' => 30]],
        ['n' => "Ingénierie et construction de séance", 'rem' => 65,  'v' => ['B' => 50, 'P' => 15]],
        ['n' => "Techniques d'animation",               'rem' => 35,  'v' => ['T' => 35]],
        ['n' => "Gestion des conflits",                 'rem' => 10,  'v' => []],
        ['n' => "Gestion du stress",                    'rem' => 20,  'v' => ['P' => 10]],
        ['n' => "Anticipation des risques",             'rem' => 25,  'v' => ['T' => 5]],
        ['n' => "Évaluation et docimologie",            'rem' => 30,  'v' => ['B' => 5]],
        ['n' => "Difficultés et remédiation",           'rem' => 20,  'v' => ['P' => 5]],
        ['n' => "Analyse de la demande (CCP2)",         'rem' => 110, 'v' => ['E' => 30]],
        ['n' => "Construction d'une action",            'rem' => 60,  'v' => ['B' => 30, 'T' => 10]],
        ['n' => "Animation d'une sensibilisation",      'rem' => 35,  'v' => ['P' => 5, 'E' => 10]],
        ['n' => "Évaluation des pratiques",             'rem' => 30,  'v' => ['P' => 10]],
    ];
}

// Semaines véhicule/regroupement configurables (Paramètres ECSR). Appeler
// lia_week_config($vehWeeks, $salleWeeks) une fois par requête avant tout
// autre appel lia_*, sinon les valeurs par défaut (S7·S13·S19 / S25·S31)
// s'appliquent.
function lia_week_config(?array $vehWeeks = null, ?array $salleWeeks = null): array {
    static $cfg = ['veh' => [7, 13, 19], 'salle' => [25, 31]];
    if ($vehWeeks !== null)   $cfg['veh']   = $vehWeeks;
    if ($salleWeeks !== null) $cfg['salle'] = $salleWeeks;
    return $cfg;
}

function lia_wtype(int $rel): string {
    $cfg = lia_week_config();
    if ($rel === 35) return 'e';
    if ($rel <= 32 && $rel % 4 === 0) return 's';
    if (in_array($rel, $cfg['veh'], true)) return 'r';
    if (in_array($rel, $cfg['salle'], true)) return 'rs';
    if ($rel === 26) return 'e1';
    return 'c';
}

function lia_dist_weeks(): array {
    $weeks = [];
    for ($i = 1; $i <= 34; $i++) {
        $t = lia_wtype($i);
        if ($t === 'c' || $t === 'e1') $weeks[] = $i;
    }
    return $weeks;
}

function lia_parse_startdate(?string $startdate): ?int {
    if (!$startdate) return null;
    $s = strlen($startdate) === 7 ? $startdate . '-01' : $startdate;
    $ts = strtotime($s);
    return $ts !== false ? $ts : null;
}

function lia_class_letter(string $className): string {
    if (preg_match('/Classe\s+([A-Za-z])/u', $className, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

function lia_hab_match(array $habs, array $keywords): bool {
    foreach ($habs as $h) {
        $text = mb_strtolower($h['text'] ?? '');
        foreach ($keywords as $k) {
            if ($text !== '' && mb_strpos($text, mb_strtolower($k)) !== false) return true;
        }
    }
    return false;
}

// $formateurs : liste de ['id','nom','role_label','type','habs','capacity']
function lia_build_people(array $formateurs): array {
    $people = [];
    foreach ($formateurs as $f) {
        $people[] = [
            'id'      => (int)$f['id'],
            'nom'     => $f['nom'],
            'role'    => $f['role_label'] ?: $f['nom'],
            'cap'     => $f['capacity'] ?: 28,
            'secteur' => trim($f['secteur'] ?? ''),
        ];
    }
    return $people;
}

// Attribution des rôles B(référent péda)/P(psychologue)/E(expression orale)/J(juriste)/T(véhicule).
//
// B/P/E/J sont mutuellement exclusifs (un même formateur ne peut porter deux
// de ces rôles à la fois, sinon il cumule leurs blocs de curriculum sur
// toutes les classes — cause d'une charge simulée irréaliste). Le rôle
// déclaré (role_label, saisi explicitement par l'admin) est toujours
// prioritaire sur une habilitation : une habilitation comme "BAFM" est une
// certification quasi universelle chez les moniteurs, elle ne suffit pas à
// identifier spécifiquement le référent pédagogique.
//
// Le pool véhicule (T) reste indépendant de B/P/E/J : dans une petite équipe,
// il est normal qu'un référent pédagogique ou un juriste soit *aussi*
// superviseur véhicule (la répartition hebdomadaire/le lissage de charge se
// chargent ensuite d'équilibrer si ça devient trop pour une seule personne).
function lia_build_prof2p(array $formateurs): array {
    $roles = [
        'B' => ['BAFM', 'référent pédagogique'],
        'P' => ['psychologue'],
        'E' => ['expression orale'],
        'J' => ['juriste'],
    ];
    $claimed = [];
    $p2p = [];

    // Passe 1 : rôle explicitement déclaré (role_label).
    foreach ($roles as $key => $keywords) {
        foreach ($formateurs as $f) {
            if (isset($claimed[$f['id']])) continue;
            $roleLabel = mb_strtolower($f['role_label'] ?? '');
            $matched = false;
            foreach ($keywords as $k) {
                if ($roleLabel !== '' && mb_strpos($roleLabel, mb_strtolower($k)) !== false) { $matched = true; break; }
            }
            if ($matched) { $p2p[$key] = (int)$f['id']; $claimed[$f['id']] = true; break; }
        }
    }
    // Passe 2 : rôles encore vacants → habilitation correspondante, chez un
    // formateur pas déjà affecté à un autre rôle spécial.
    foreach ($roles as $key => $keywords) {
        if (isset($p2p[$key])) continue;
        foreach ($formateurs as $f) {
            if (isset($claimed[$f['id']])) continue;
            if (lia_hab_match($f['habs'] ?? [], $keywords)) { $p2p[$key] = (int)$f['id']; $claimed[$f['id']] = true; break; }
        }
    }

    $tFs = array_values(array_filter($formateurs, function ($f) {
        return lia_hab_match($f['habs'] ?? [], ['superviseur véhicule', 'moniteur', 'monitrice', 'titre pro ecsr']);
    }));
    $p2p['_T_ids'] = array_map(fn($f) => (int)$f['id'], $tFs);
    if (isset($tFs[0])) $p2p['T']  = (int)$tFs[0]['id'];
    if (isset($tFs[1])) $p2p['T2'] = (int)$tFs[1]['id'];
    return $p2p;
}

// Les superviseurs véhicule se sauvegardent mutuellement ; le référent péda peut déléguer aux superviseurs
function lia_build_backups(array $prof2p, array $people): array {
    $bk = [];
    foreach ($people as $p) $bk[$p['id']] = [];
    $tIds = $prof2p['_T_ids'] ?? [];
    foreach ($tIds as $id) {
        if (isset($bk[$id])) $bk[$id] = array_values(array_filter($tIds, fn($x) => $x !== $id));
    }
    if (!empty($prof2p['B']) && isset($bk[$prof2p['B']])) {
        foreach ($tIds as $tid) {
            if (!in_array($tid, $bk[$prof2p['B']], true)) $bk[$prof2p['B']][] = $tid;
        }
    }
    return $bk;
}

// Date (timestamp) de la cohorte la plus ancienne parmi $classes (['startdate' => ...]).
function lia_earliest_start(array $classes): ?int {
    $classes = array_values(array_filter($classes, fn($c) => !empty($c['startdate'])));
    if (!$classes) return null;
    usort($classes, fn($a, $b) => (lia_parse_startdate($a['startdate']) ?? 0) <=> (lia_parse_startdate($b['startdate']) ?? 0));
    return lia_parse_startdate($classes[0]['startdate']);
}

// $classes : liste de ['name','startdate','nb_members','secteur'] — startdate
// stocké dans cohort.idnumber, secteur dans cohort.description (tous deux
// des champs natifs Moodle réaffectés, aucun n'étant utilisé nativement ici).
function lia_build_cohorts(array $classes): array {
    $classes = array_values(array_filter($classes, fn($c) => !empty($c['startdate'])));
    if (!$classes) return [];
    $earliest = lia_earliest_start($classes);
    if ($earliest === null) return [];
    usort($classes, fn($a, $b) => (lia_parse_startdate($a['startdate']) ?? 0) <=> (lia_parse_startdate($b['startdate']) ?? 0));
    $cohorts = [];
    foreach ($classes as $c) {
        $sd = lia_parse_startdate($c['startdate']);
        $off = $sd !== null ? max(0, (int)round(($sd - $earliest) / (7 * 86400))) : 0;
        $letter = lia_class_letter($c['name']) ?: mb_substr($c['name'], 0, 1);
        $cohorts[] = [
            'L' => $letter, 'off' => $off, 'name' => $c['name'], 'nb_members' => $c['nb_members'] ?? 0,
            'secteur' => trim($c['secteur'] ?? ''),
        ];
    }
    return $cohorts;
}

// Filtre $ids pour ne garder QUE les formateurs du même secteur que
// $secteur (ordre relatif conservé) — quitte à ne renvoyer qu'une seule
// personne au lieu de deux si un seul formateur de ce secteur existe. On ne
// propose jamais quelqu'un d'un autre secteur en complément : mieux vaut un
// seul superviseur du bon secteur que deux dont un qui doit faire le trajet.
// Si personne ne correspond encore (secteur de classe non renseigné, ou
// renseigné mais aucun formateur de l'équipe ne le porte pour l'instant), on
// revient au comportement historique ($ids tel quel) plutôt que de laisser
// la classe sans aucun superviseur véhicule/salle.
function lia_prioritize_by_secteur(array $ids, string $secteur, array $secteurById): array {
    $secteur = mb_strtolower(trim($secteur));
    if ($secteur === '') return $ids;
    $match = [];
    foreach ($ids as $id) {
        if (mb_strtolower(trim($secteurById[$id] ?? '')) === $secteur) $match[] = $id;
    }
    return $match ?: $ids;
}

function lia_total_weeks(array $cohorts): int {
    $maxOff = 0;
    foreach ($cohorts as $c) { if ($c['off'] > $maxOff) $maxOff = $c['off']; }
    return 35 + $maxOff;
}

function lia_week_total(array $grid, $personId, int $week): float {
    if (!isset($grid[$personId][$week])) return 0.0;
    $s = 0.0;
    foreach ($grid[$personId][$week] as $e) $s += $e->h;
    return $s;
}

/**
 * Transforme des lignes l3m_dispos (objets avec ->userid, ->date_from, ->date_to,
 * dates 'YYYY-MM-DD') en carte [userid => [semaine, semaine, ...]], relative à
 * $refStart (timestamp de la cohorte la plus ancienne). Ne filtre pas par statut —
 * c'est à l'appelant de ne passer que les lignes validées.
 */
function lia_build_unavailable(array $dispoRows, ?int $refStart, int $totalW): array {
    if ($refStart === null) return [];
    $unavailable = [];
    foreach ($dispoRows as $row) {
        $from = strtotime($row->date_from . ' 00:00:00');
        $to   = strtotime($row->date_to   . ' 23:59:59');
        if ($from === false || $to === false || $to < $from) continue;
        $uid = (int)$row->userid;
        for ($w = 1; $w <= $totalW; $w++) {
            $wStart = $refStart + ($w - 1) * 7 * 86400;
            $wEnd   = $wStart + 7 * 86400 - 1;
            if ($from <= $wEnd && $to >= $wStart) {
                $unavailable[$uid][] = $w;
            }
        }
    }
    return $unavailable;
}

/**
 * Applique des affectations manuelles (admin, "Planning & Lia") sur la grille :
 * pour chaque override, retire les entrées auto-générées de ce formateur pour
 * cette classe à cette semaine, puis (si h > 0) les remplace par l'entrée
 * manuelle. Marquée fix:true — donc protégée du lissage automatique au même
 * titre que les séances véhicule/salle, ce qui la rend définitive tant
 * qu'elle n'est pas explicitement supprimée ou resaisie.
 *
 * $overrides : liste de ['pid','w','coh','type','label','h'] (issue de
 * lia_overrides en config, voir save_lia_override/delete_lia_override).
 */
function lia_apply_overrides(array &$grid, array $overrides): void {
    foreach ($overrides as $ov) {
        $pid = (int)($ov['pid'] ?? 0);
        $w   = (int)($ov['w'] ?? 0);
        $coh = (string)($ov['coh'] ?? '');
        if (!$pid || !$w || $coh === '' || !isset($grid[$pid][$w])) continue;
        $grid[$pid][$w] = array_values(array_filter($grid[$pid][$w], fn($e) => $e->coh !== $coh));
        $h = (float)($ov['h'] ?? 0);
        if ($h > 0) {
            $e = new stdClass();
            $e->coh    = $coh;
            $e->type   = (string)($ov['type'] ?? 'visio');
            $e->label  = (string)($ov['label'] ?? 'Affectation manuelle');
            $e->h      = $h;
            $e->fix    = true;
            $e->manual = true;
            $grid[$pid][$w][] = $e;
        }
    }
}

/**
 * Retire de $alerts celles dont la clé "kind|pid|w" figure dans $dismissed
 * (voir dismiss_lia_alert). Fonction pure — ne lit/écrit aucune config, à
 * appeler par chaque consommateur (admin-lba.php, espace-formateur.php)
 * après lia_compute_plan().
 */
function lia_filter_dismissed_alerts(array $alerts, array $dismissed): array {
    if (!$dismissed) return $alerts;
    $dismissed = array_flip($dismissed);
    return array_values(array_filter($alerts, function ($a) use ($dismissed) {
        $key = ($a['kind'] ?? '') . '|' . ($a['pid'] ?? '') . '|' . ($a['w'] ?? '');
        return !isset($dismissed[$key]);
    }));
}

/**
 * Calcule la grille personne × semaine, affecte les blocs de curriculum par rôle,
 * puis rééquilibre automatiquement les semaines en surcharge (délégation à un
 * backup, puis décalage ±1..4 semaines). Retourne grid/log/alerts/etc.
 *
 * $unavailable (optionnel) : [personId => [semaine, ...]] — semaines où la
 * capacité effective de cette personne est forcée à 0 (indisponibilité
 * validée). Voir lia_build_unavailable(). Les séances fixes (véhicule/salle,
 * fix:true) ne sont pas réaffectées par ce mécanisme — voir alerte dédiée.
 *
 * $overrides (optionnel) : affectations manuelles admin, voir lia_apply_overrides().
 * Appliquées avant le tutorat hebdo / lissage, pour que ces derniers
 * raisonnent sur l'état déjà corrigé.
 */
function lia_compute_plan(array $cohorts, array $people, array $prof2p, array $backups, array $unavailable = [], array $overrides = []): array {
    $totalW = lia_total_weeks($cohorts);

    $grid = [];
    foreach ($people as $p) {
        $grid[$p['id']] = [];
        for ($i = 0; $i <= $totalW + 1; $i++) $grid[$p['id']][$i] = [];
    }

    $add = function ($pid, $w, $entry) use (&$grid, $totalW) {
        if ($pid === null) return;
        if ($w >= 1 && $w <= $totalW && isset($grid[$pid])) {
            $grid[$pid][$w][] = $entry;
        }
    };
    $tot = function ($pid, $w) use (&$grid) {
        if ($pid === null || !isset($grid[$pid][$w])) return 0.0;
        $s = 0.0;
        foreach ($grid[$pid][$w] as $e) $s += $e->h;
        return $s;
    };

    $distWeeks = lia_dist_weeks();
    $blocs     = lia_blocs();

    $secteurById = [];
    foreach ($people as $p) { $secteurById[$p['id']] = $p['secteur'] ?? ''; }

    // ── Affectation des blocs de cours + semaines véhicule/salle fixes ──
    foreach ($cohorts as $cohort) {
        $L = $cohort['L']; $off = $cohort['off']; $cum = 0;
        foreach ($blocs as $b) {
            $i0 = (int)floor($cum / 35);
            $i1 = (int)ceil(($cum + $b['rem']) / 35);
            for ($i = $i0; $i < $i1 && $i < count($distWeeks); $i++) {
                $frac = (min($cum + $b['rem'], ($i + 1) * 35) - max($cum, $i * 35)) / $b['rem'];
                $w    = $distWeeks[$i] + $off;
                foreach ($b['v'] as $pr => $baseH) {
                    $h = $baseH * $frac;
                    if ($h < 0.75) continue;
                    if ($pr === 'T') {
                        $tIds = $prof2p['_T_ids'] ?? [];
                        if (count($tIds) >= 2) {
                            $e1 = new stdClass(); $e1->coh = $L; $e1->type = 'visio'; $e1->label = $b['n']; $e1->h = $h / 2;
                            $add($tIds[0], $w, $e1);
                            $e2 = new stdClass(); $e2->coh = $L; $e2->type = 'visio'; $e2->label = $b['n']; $e2->h = $h / 2;
                            $add($tIds[1], $w, $e2);
                        } elseif (count($tIds) === 1) {
                            $e1 = new stdClass(); $e1->coh = $L; $e1->type = 'visio'; $e1->label = $b['n']; $e1->h = $h;
                            $add($tIds[0], $w, $e1);
                        }
                    } elseif (!empty($prof2p[$pr])) {
                        $e1 = new stdClass(); $e1->coh = $L; $e1->type = 'visio'; $e1->label = $b['n']; $e1->h = $h;
                        $add($prof2p[$pr], $w, $e1);
                    }
                }
            }
            $cum += $b['rem'];
        }

        $cfg  = lia_week_config();
        $tIds = $prof2p['_T_ids'] ?? [];
        // Formateurs du même secteur que la classe en tête de pool — à
        // habilitation égale, on préfère qui n'a pas à se déplacer loin pour
        // superviser le véhicule/la salle de cette classe.
        $tIdsCoh = lia_prioritize_by_secteur($tIds, $cohort['secteur'] ?? '', $secteurById);
        foreach ($cfg['veh'] as $r) {
            foreach (array_slice($tIdsCoh, 0, 2) as $tid) {
                $e = new stdClass(); $e->coh = $L; $e->type = 'veh'; $e->label = 'Semaine véhicule'; $e->h = 28; $e->fix = true;
                $add($tid, $r + $off, $e);
            }
        }
        $bId = $prof2p['B'] ?? null;
        // Le référent pédagogique porte par défaut les 2 "Regroupement"
        // nommés — mais s'il n'est pas du secteur de cette classe (et qu'un
        // secteur est bien renseigné des deux côtés), on ne le fait pas non
        // plus voyager : ce rôle bascule sur le formateur local qui assure
        // déjà l'appui véhicule/salle de la classe.
        $cohSecteur = mb_strtolower(trim($cohort['secteur'] ?? ''));
        $bSecteur   = mb_strtolower(trim($secteurById[$bId] ?? ''));
        $bIdForCoh  = $bId;
        if ($bId && $cohSecteur !== '' && $bSecteur !== '' && $bSecteur !== $cohSecteur && !empty($tIdsCoh)) {
            $bIdForCoh = $tIdsCoh[0];
        }
        $salleLabels = ['Regroupement — mises en situation', 'Regroupement — examens blancs'];
        foreach ($cfg['salle'] as $sIdx => $sw) {
            $label = $salleLabels[$sIdx] ?? ('Regroupement — session ' . ($sIdx + 1));
            if ($bIdForCoh) {
                $e = new stdClass(); $e->coh = $L; $e->type = 'salle'; $e->label = $label; $e->h = 20; $e->fix = true;
                $add($bIdForCoh, $sw + $off, $e);
            }
            if (!empty($tIdsCoh)) {
                $tid = $tIdsCoh[$sIdx % count($tIdsCoh)];
                $e = new stdClass(); $e->coh = $L; $e->type = 'salle'; $e->label = 'Regroupement — appui'; $e->h = 15; $e->fix = true;
                $add($tid, $sw + $off, $e);
            }
        }
    }

    // Affectations manuelles admin : appliquées avant tutorat/lissage pour
    // que ceux-ci raisonnent sur l'état déjà corrigé (voir lia_apply_overrides).
    if ($overrides) lia_apply_overrides($grid, $overrides);

    // ── Tutorat hebdo (attribué à qui est le moins chargé cette semaine-là) ──
    foreach ($cohorts as $cohort) {
        $L = $cohort['L']; $off = $cohort['off'];
        for ($rel = 1; $rel <= 34; $rel++) {
            $t = lia_wtype($rel);
            if ($t !== 'c' && $t !== 'e1') continue;
            $w = $rel + $off;
            if ($w < 1 || $w > $totalW) continue;
            $cands = $prof2p['_T_ids'] ?? [];
            if (!empty($prof2p['B']) && !in_array($prof2p['B'], $cands, true)) $cands[] = $prof2p['B'];
            if (!$cands) continue;
            $best = null; $bestTot = null;
            foreach ($cands as $cid) {
                $ct = $tot($cid, $w);
                if ($bestTot === null || $ct < $bestTot) { $bestTot = $ct; $best = $cid; }
            }
            $e = new stdClass(); $e->coh = $L; $e->type = 'visio'; $e->label = 'Suivi hebdo (tutorat)'; $e->h = 2;
            $add($best, $w, $e);
        }
    }

    // ── Semaines d'arrêt (toutes les cohortes en stage simultanément) ──
    $idle = [];
    for ($w = 1; $w <= $totalW; $w++) {
        $act = [];
        foreach ($cohorts as $c) {
            $r = $w - $c['off'];
            if ($r >= 1 && $r <= 34) $act[] = $r;
        }
        if ($act) {
            $allStage = true;
            foreach ($act as $r) { if (lia_wtype($r) !== 's') { $allStage = false; break; } }
            if ($allStage) $idle[] = $w;
        }
    }

    // ── Conflits véhicule simultanés ──
    $alerts = [];
    for ($w = 1; $w <= $totalW; $w++) {
        $tIds0 = $prof2p['_T_ids'] ?? [];
        if (!empty($tIds0)) {
            $vehCohs = [];
            foreach (($grid[$tIds0[0]][$w] ?? []) as $e) {
                if ($e->type === 'veh') $vehCohs[] = $e->coh;
            }
            if (count($vehCohs) > 1) {
                $alerts[] = [
                    'kind'=> 'veh_conflict',
                    'pid' => null,
                    'w'   => $w,
                    'txt' => 'S' . $w . ' : classes ' . implode(' et ', $vehCohs) . ' en semaine véhicule simultanée — moniteurs insuffisants. Lia recommande de décaler une rentrée.',
                ];
            }
        }
    }

    // ── Lissage de charge par Lia (délégation puis décalage ±1..4 semaines) ──
    $log = []; $movedH = 0.0; $touchedW = [];
    $courseOK = function ($coh, $w2) use ($cohorts) {
        $c = null;
        foreach ($cohorts as $x) { if ($x['L'] === $coh) { $c = $x; break; } }
        if (!$c) return false;
        $rel2 = $w2 - $c['off'];
        return $rel2 >= 1 && $rel2 <= 34 && (lia_wtype($rel2) === 'c' || lia_wtype($rel2) === 'e1');
    };

    for ($w = 1; $w <= $totalW; $w++) {
        foreach ($people as $p) {
            $pid           = $p['id'];
            $isUnavailable = isset($unavailable[$pid]) && in_array($w, $unavailable[$pid], true);
            $effCap        = $isUnavailable ? 0 : $p['cap'];

            // Les séances fixes (véhicule/salle) ne sont pas réaffectables : le
            // signaler explicitement plutôt que de tenter de les déplacer.
            if ($isUnavailable) {
                foreach (($grid[$pid][$w] ?? []) as $e) {
                    if (!empty($e->fix)) {
                        $alerts[] = [
                            'kind'=> 'fixed_unavailable',
                            'pid' => $pid, 'w' => $w,
                            'txt' => 'S' . $w . ' : ' . $p['nom'] . ' indisponible — « ' . $e->label . ' » (classe ' . $e->coh . ') est une séance fixe, réaffectation manuelle nécessaire.',
                        ];
                    }
                }
            }

            $excess = $tot($pid, $w) - $effCap;
            if ($excess <= 0.5) continue;
            $before = (int)round($tot($pid, $w));

            // Délégation à backup
            foreach (($backups[$pid] ?? []) as $bk) {
                if ($excess <= 0.5) break;
                $bkPers = null;
                foreach ($people as $x) { if ($x['id'] === $bk) { $bkPers = $x; break; } }
                $bkCap = $bkPers ? $bkPers['cap'] : 20;

                $candidates = [];
                foreach (($grid[$pid][$w] ?? []) as $e) {
                    if (empty($e->fix) && empty($e->lia)) $candidates[] = $e;
                }
                usort($candidates, fn($a, $b) => $b->h <=> $a->h);

                foreach ($candidates as $e) {
                    if ($excess <= 0.5) break;
                    $m = min($e->h, $excess, $bkCap - $tot($bk, $w));
                    if ($m < 1) continue;
                    $e->h -= $m;
                    if ($e->h < 0.5) {
                        $grid[$pid][$w] = array_values(array_filter($grid[$pid][$w], fn($x) => $x !== $e));
                    }
                    $ne = new stdClass(); $ne->coh = $e->coh; $ne->type = 'visio'; $ne->label = $e->label; $ne->h = $m; $ne->lia = true;
                    $grid[$bk][$w][] = $ne;
                    $log[] = [
                        'pid' => $pid, 'w' => $w,
                        'txt' => $p['nom'] . ' était à ' . $before . ' h : Lia délègue ' . (int)round($m) . ' h « ' . $e->label . ' » (classe ' . $e->coh . ') à ' . ($bkPers ? $bkPers['nom'] : '?') . '.',
                    ];
                    $movedH += $m; $touchedW[$w] = 1;
                    $excess = $tot($pid, $w) - $effCap;
                }
            }

            // Décalage temporel
            foreach ([-2, -1, 1, 2, 3, 4] as $d) {
                if ($excess <= 0.5) break;
                $w2 = $w + $d;
                if ($w2 < 1 || $w2 > $totalW) continue;
                $effCap2 = (isset($unavailable[$pid]) && in_array($w2, $unavailable[$pid], true)) ? 0 : $p['cap'];

                $candidates = [];
                foreach (($grid[$pid][$w] ?? []) as $e) {
                    if (empty($e->fix) && empty($e->lia) && $courseOK($e->coh, $w2)) $candidates[] = $e;
                }
                usort($candidates, fn($a, $b) => $b->h <=> $a->h);

                foreach ($candidates as $e) {
                    if ($excess <= 0.5) break;
                    $m = min($e->h, $excess, $effCap2 - $tot($pid, $w2));
                    if ($m < 1) continue;
                    $e->h -= $m;
                    if ($e->h < 0.5) {
                        $grid[$pid][$w] = array_values(array_filter($grid[$pid][$w], fn($x) => $x !== $e));
                    }
                    $ne = new stdClass(); $ne->coh = $e->coh; $ne->type = 'visio'; $ne->label = $e->label; $ne->h = $m; $ne->lia = true;
                    $grid[$pid][$w2][] = $ne;
                    $log[] = [
                        'pid' => $pid, 'w' => $w,
                        'txt' => $p['nom'] . ' était à ' . $before . ' h : Lia ' . ($d < 0 ? 'anticipe' : 'décale') . ' ' . (int)round($m) . ' h « ' . $e->label . ' » (classe ' . $e->coh . ') vers S' . $w2 . '.',
                    ];
                    $movedH += $m; $touchedW[$w] = 1;
                    $excess = $tot($pid, $w) - $effCap;
                }
            }

            if ($excess > 0.5) {
                $alerts[] = [
                    'kind'=> $isUnavailable ? 'unavailable_excess' : 'overload',
                    'pid' => $pid,
                    'w'   => $w,
                    'txt' => $isUnavailable
                        ? 'S' . $w . ' : ' . $p['nom'] . ' indisponible, ' . (int)round($tot($pid, $w)) . ' h à réaffecter manuellement.'
                        : 'S' . $w . ' : ' . $p['nom'] . ' reste à ' . (int)round($tot($pid, $w)) . ' h (cap. ' . $p['cap'] . ' h). Accepter, renforcer, ou déplacer une semaine véhicule.',
                ];
            }
        }
    }

    return [
        'grid'    => $grid,
        'log'     => $log,
        'alerts'  => $alerts,
        'movedH'  => round($movedH),
        'weeks'   => count($touchedW),
        'totalW'  => $totalW,
        'idle'    => $idle,
        'cohorts' => $cohorts,
    ];
}

// ============================================================
// Notifications L3M — écrit directement dans les tables natives Moodle
// {notifications} + {message_popup_notifications}, reproduisant ce que
// message_send() + le processeur message_output_popup font en interne,
// pour apparaître dans le popup de notifications sans passer par un
// message provider/plugin déclaré. Partagé par admin-lba.php et
// espace-formateur.php.
// ============================================================
function l3m_send_notification(int $useridfrom, int $useridto, string $subject, string $message, string $contexturl = '', string $contexturlname = ''): ?int {
    global $DB;
    if ($useridfrom === $useridto) return null;

    $rec = new stdClass();
    $rec->useridfrom      = $useridfrom;
    $rec->useridto        = $useridto;
    $rec->subject         = $subject;
    $rec->fullmessage     = $message;
    $rec->fullmessageformat = FORMAT_HTML;
    $rec->fullmessagehtml = $message;
    $rec->smallmessage    = $message;
    $rec->component       = 'local_l3madmin';
    $rec->eventtype       = 'l3m_notification';
    $rec->contexturl      = $contexturl;
    $rec->contexturlname  = $contexturlname;
    $rec->timecreated     = time();

    $notifid = $DB->insert_record('notifications', $rec);
    $DB->insert_record('message_popup_notifications', (object)['notificationid' => $notifid]);
    return $notifid;
}
