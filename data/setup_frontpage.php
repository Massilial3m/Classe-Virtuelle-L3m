<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Configuration Page Accueil – L3M Formation');

global $DB, $USER;
$USER = get_admin();

// 1. Désactiver la page d'accueil Moodle (supprimer forum annonces etc.)
set_config('frontpage', '');
set_config('frontpageloggedin', '');
cli_writeln('Frontpage Moodle vidée.');

// 2. Activer le page builder pour la page d'accueil
set_config('pbfp', 1, 'theme_mb2nl');
cli_writeln('Page builder activé pour la page d\'accueil.');

// 3. Supprimer les pages builder existantes pour la homepage (mpage=-1)
$DB->delete_records('local_mb2builder_pages', ['mpage' => -1]);
cli_writeln('Pages builder existantes supprimées.');

// 4. Créer le contenu JSON pour la page d'accueil
// Structure: [page > sections > rows > columns > elements]
// La page contient 2 sections : Hero + Formations

$hero_text_content = 'Préparez-vous aux certifications ECSR et accédez à une formation 100% en ligne, adaptée à votre rythme.';

$content = [
    // Page wrapper
    (object)[
        'attr' => [
            // ==================== SECTION 1 : HERO ====================
            (object)[
                'settings' => (object)[
                    'admin_label' => 'Hero',
                    'pt'          => 80,
                    'pb'          => 80,
                    'fluid'       => 1,
                    'bgcolor'     => '#1A1A1A',
                    'scheme'      => 'dark',
                ],
                'attr' => [
                    (object)[
                        'settings' => (object)[
                            'admin_label' => 'Row Hero',
                            'gutter'      => 'none',
                        ],
                        'attr' => [
                            (object)[
                                'settings' => (object)[
                                    'id'     => 'pbcolumn',
                                    'col'    => 12,
                                    'pt'     => 0,
                                    'pb'     => 0,
                                    'align'  => 'center',
                                    'alignc' => 'center',
                                    'scheme' => 'dark',
                                ],
                                'attr' => [
                                    // Heading
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'heading',
                                            'admin_label'  => 'Titre principal',
                                            'tag'          => 'h1',
                                            'size'         => 2.8,
                                            'align'        => 'center',
                                            'color'        => '#C9A227',
                                            'fwcls'        => 'bold',
                                            'mt'           => 0,
                                            'mb'           => 20,
                                            'text'         => 'Formation ECSR – Titre Professionnel',
                                        ],
                                    ],
                                    // Sous-titre
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'text',
                                            'admin_label'  => 'Sous-titre',
                                            'size'         => 1.1,
                                            'align'        => 'center',
                                            'color'        => '#FFFFFF',
                                            'mt'           => 0,
                                            'mb'           => 30,
                                            'content'      => $hero_text_content,
                                        ],
                                    ],
                                    // Bouton CTA
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'button',
                                            'admin_label'  => 'Bouton CTA',
                                            'text'         => 'Découvrir nos formations',
                                            'link'         => $CFG->wwwroot . '/course/index.php',
                                            'size'         => 'lg',
                                            'align'        => 'center',
                                            'rounded'      => 1,
                                            'bgcolor'      => '#C9A227',
                                            'color'        => '#1A1A1A',
                                            'mt'           => 0,
                                            'mb'           => 10,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // ==================== SECTION 2 : NOS FORMATIONS ====================
            (object)[
                'settings' => (object)[
                    'admin_label' => 'Nos formations',
                    'pt'          => 60,
                    'pb'          => 60,
                    'bgcolor'     => '#F5F5F5',
                    'scheme'      => 'light',
                ],
                'attr' => [
                    (object)[
                        'settings' => (object)[
                            'admin_label' => 'Row Formations',
                            'gutter'      => 'normal',
                        ],
                        'attr' => [
                            (object)[
                                'settings' => (object)[
                                    'id'     => 'pbcolumn',
                                    'col'    => 12,
                                    'pt'     => 0,
                                    'pb'     => 0,
                                    'align'  => 'center',
                                    'alignc' => 'center',
                                    'scheme' => 'light',
                                ],
                                'attr' => [
                                    // Titre section
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'heading',
                                            'admin_label'  => 'Titre Formations',
                                            'tag'          => 'h2',
                                            'size'         => 1.8,
                                            'align'        => 'center',
                                            'color'        => '#1A1A1A',
                                            'fwcls'        => 'bold',
                                            'mt'           => 0,
                                            'mb'           => 40,
                                            'text'         => 'Nos formations',
                                        ],
                                    ],
                                    // Grille de cours
                                    (object)[
                                        'settings' => (object)[
                                            'id'              => 'courses',
                                            'admin_label'     => 'Grille formations',
                                            'columns'         => 3,
                                            'limit'           => 12,
                                            'carousel'        => 0,
                                            'gutter'          => 'normal',
                                            'mt'              => 0,
                                            'mb'              => 0,
                                            'crounded'        => 1,
                                            'courseprice'     => 0,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // ==================== SECTION 3 : CLASSE VIRTUELLE ====================
            (object)[
                'settings' => (object)[
                    'admin_label' => 'Classe virtuelle',
                    'pt'          => 60,
                    'pb'          => 60,
                    'bgcolor'     => '#1A1A1A',
                    'scheme'      => 'dark',
                ],
                'attr' => [
                    (object)[
                        'settings' => (object)[
                            'admin_label' => 'Row Virtuelle',
                            'gutter'      => 'none',
                        ],
                        'attr' => [
                            (object)[
                                'settings' => (object)[
                                    'id'     => 'pbcolumn',
                                    'col'    => 12,
                                    'pt'     => 0,
                                    'pb'     => 0,
                                    'align'  => 'center',
                                    'alignc' => 'center',
                                    'scheme' => 'dark',
                                ],
                                'attr' => [
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'heading',
                                            'admin_label'  => 'Titre Classe Virtuelle',
                                            'tag'          => 'h2',
                                            'size'         => 1.8,
                                            'align'        => 'center',
                                            'color'        => '#C9A227',
                                            'fwcls'        => 'bold',
                                            'mt'           => 0,
                                            'mb'           => 20,
                                            'text'         => 'Classe virtuelle intégrée',
                                        ],
                                    ],
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'text',
                                            'admin_label'  => 'Texte Classe Virtuelle',
                                            'size'         => 1.05,
                                            'align'        => 'center',
                                            'color'        => '#FFFFFF',
                                            'mt'           => 0,
                                            'mb'           => 30,
                                            'content'      => 'Participez à des sessions live avec votre formateur, directement depuis la plateforme. Sessions d\'1h à 2h, jusqu\'à 25 participants.',
                                        ],
                                    ],
                                    (object)[
                                        'settings' => (object)[
                                            'id'           => 'button',
                                            'admin_label'  => 'Bouton Connexion',
                                            'text'         => 'Accéder à mon espace',
                                            'link'         => $CFG->wwwroot . '/login/index.php',
                                            'size'         => 'lg',
                                            'align'        => 'center',
                                            'rounded'      => 1,
                                            'bgcolor'      => '#C9A227',
                                            'color'        => '#1A1A1A',
                                            'mt'           => 0,
                                            'mb'           => 0,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$content_json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// 5. Insérer la page builder record pour la frontpage (mpage = -1)
$now = time();
$admin = get_admin();

$page_record = (object)[
    'pageid'      => 'fp',
    'mpage'       => -1,
    'timecreated' => $now,
    'timemodified' => $now,
    'title'       => 'Page d\'accueil L3M Formation',
    'content'     => $content_json,
    'democontent' => '',
    'createdby'   => $admin->id,
    'modifiedby'  => $admin->id,
    'heading'     => 0,
    'footer'      => 0,
    'headerstyle' => '',
    'pagecss'     => '',
    'menu'        => 0,
    'tgsdb'       => 0,
];

$newid = $DB->insert_record('local_mb2builder_pages', $page_record);
cli_writeln('Page builder créée pour la frontpage (mpage=-1), id=' . $newid);

// 6. Vider les caches
purge_all_caches();
cli_writeln('Caches vidés.');
cli_writeln('');
cli_writeln('Page d\'accueil configurée avec succès. Visitez http://' . $_SERVER['HTTP_HOST'] ?? '151.80.61.51' . '/ pour voir le résultat.');
