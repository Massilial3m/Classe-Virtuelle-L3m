#!/usr/bin/env python3
from fpdf import FPDF
import os

OUTPUT_DIR = "/data/dev/l3m/moodle/data/pdf_resources"
os.makedirs(OUTPUT_DIR, exist_ok=True)

FONT_DIR  = "/usr/share/fonts/truetype/dejavu/"
FONT_R    = FONT_DIR + "DejaVuSans.ttf"
FONT_B    = FONT_DIR + "DejaVuSans-Bold.ttf"

TEAL   = (46, 148, 136)    # #2E9488
TEAL_D = (28, 95, 88)      # #1C5F58
GREY   = (235, 245, 243)   # teal tint light
WHITE  = (255, 255, 255)

def make_pdf(filename, title, module, ccp, objectifs, sections):
    pdf = FPDF()
    pdf.add_font("DejaVu",  "", FONT_R, uni=True)
    pdf.add_font("DejaVu",  "B", FONT_B, uni=True)
    pdf.add_page()
    pdf.set_auto_page_break(auto=True, margin=22)

    # En-tête teal
    pdf.set_fill_color(*TEAL_D)
    pdf.rect(0, 0, 210, 28, 'F')
    pdf.set_fill_color(*TEAL)
    pdf.rect(0, 28, 210, 3, 'F')

    pdf.set_text_color(*WHITE)
    pdf.set_font("DejaVu", "B", 18)
    pdf.set_xy(15, 7)
    pdf.cell(0, 10, "L3M Formation")

    pdf.set_font("DejaVu", "", 9)
    pdf.set_xy(15, 19)
    pdf.cell(0, 5, "TP ECSR – RNCP 35329 | Lindberg Formation")

    # Bloc titre
    pdf.set_xy(15, 38)
    pdf.set_text_color(*TEAL_D)
    pdf.set_font("DejaVu", "B", 11)
    pdf.cell(0, 7, ccp)

    pdf.set_xy(15, 47)
    pdf.set_text_color(36, 51, 60)
    pdf.set_font("DejaVu", "B", 15)
    pdf.multi_cell(180, 8, title)

    pdf.set_font("DejaVu", "", 9)
    pdf.set_text_color(100, 100, 100)
    pdf.set_x(15)
    pdf.cell(0, 6, f"Module : {module}  |  Support de cours – Usage pédagogique interne")

    # Ligne teal
    y = pdf.get_y() + 5
    pdf.set_draw_color(*TEAL)
    pdf.set_line_width(0.8)
    pdf.line(15, y, 195, y)
    pdf.ln(10)

    # Bloc objectifs
    pdf.set_fill_color(*TEAL)
    pdf.set_text_color(*WHITE)
    pdf.set_font("DejaVu", "B", 10)
    pdf.set_x(15)
    pdf.cell(180, 8, "  Objectifs pédagogiques", fill=True)
    pdf.ln(10)

    pdf.set_text_color(36, 51, 60)
    pdf.set_font("DejaVu", "", 10)
    for obj in objectifs:
        pdf.set_x(18)
        pdf.cell(6, 7, "–")
        pdf.set_x(24)
        pdf.multi_cell(171, 7, obj)
    pdf.ln(4)

    # Sections
    for i, (section_title, lines) in enumerate(sections, 1):
        pdf.set_fill_color(*GREY)
        pdf.set_text_color(*TEAL_D)
        pdf.set_font("DejaVu", "B", 10)
        pdf.set_x(15)
        pdf.cell(180, 8, f"  {i}. {section_title}", fill=True)
        pdf.ln(10)

        pdf.set_font("DejaVu", "", 10)
        pdf.set_text_color(50, 50, 50)
        for line in lines:
            pdf.set_x(18)
            pdf.multi_cell(177, 6, line)
            pdf.ln(1)
        pdf.ln(4)

    # Pied de page
    pdf.set_y(-18)
    pdf.set_fill_color(*TEAL_D)
    pdf.rect(0, pdf.get_y(), 210, 18, 'F')
    pdf.set_text_color(*WHITE)
    pdf.set_font("DejaVu", "", 8)
    pdf.set_x(15)
    pdf.cell(90, 10, "L3M Formation – Document pédagogique confidentiel")
    pdf.set_x(120)
    pdf.cell(75, 10, "www.l3m-formation.fr", align="R")

    path = os.path.join(OUTPUT_DIR, filename)
    pdf.output(path)
    print(f"✓ {filename}")


# ── PDF 1 : CCP1 M1 – Cadre juridique ────────────────────────────────────────
make_pdf(
    filename  = "CCP1-M1-cadre-juridique.pdf",
    title     = "Cadre juridique et réglementaire de la formation à la conduite",
    module    = "M1 – CCP1",
    ccp       = "CCP1 – Animer des actions de formation à la conduite",
    objectifs = [
        "Identifier les textes réglementaires encadrant la formation à la conduite",
        "Comprendre le rôle des institutions de contrôle (DSR, DDT, DREAL)",
        "Connaître les obligations légales de l'enseignant ECSR",
    ],
    sections  = [
        ("Les textes réglementaires fondateurs", [
            "La formation à la conduite est encadrée par le Code de la route (articles R.213-1 et suivants), complété par des arrêtés ministériels précisant les conditions d'agrément des établissements d'enseignement de la conduite et de la sécurité routière (ECSR).",
            "Principaux textes à connaître : arrêté du 8 janvier 2001 relatif à l'exploitation des établissements d'enseignement, à titre onéreux, de la conduite des véhicules à moteur et de la sécurité routière.",
        ]),
        ("Le rôle des institutions", [
            "La DSR (Délégation à la Sécurité Routière) définit la politique nationale de formation à la conduite. Elle publie les circulaires et référentiels annuels.",
            "La DDT (Direction Départementale des Territoires) instruit les demandes d'agrément au niveau local et assure le suivi administratif des établissements.",
            "La DREAL et les IPCSR (Inspecteurs du Permis de Conduire et de la Sécurité Routière) réalisent les contrôles terrain.",
        ]),
        ("L'agrément des établissements ECSR", [
            "Tout établissement souhaitant dispenser des cours de conduite doit obtenir un agrément préfectoral valable 5 ans, soumis à renouvellement.",
            "Conditions requises : local adapté et conforme, véhicule double-commande homologué, enseignant titulaire du TP ECSR ou du BEPECASER avec autorisation d'enseigner en cours de validité.",
        ]),
        ("Responsabilités de l'enseignant", [
            "L'enseignant ECSR est responsable de la sécurité de l'apprenant en toutes circonstances. Sa responsabilité civile et pénale peut être engagée en cas d'accident survenant lors d'une séance de formation.",
            "Il doit maintenir son autorisation d'enseigner à jour (renouvellement tous les 5 ans), disposer d'un certificat médical valide et d'un casier judiciaire vierge (bulletin n°2).",
        ]),
    ]
)

# ── PDF 2 : CCP1 M2 – Pédagogie ──────────────────────────────────────────────
make_pdf(
    filename  = "CCP1-M2-pedagogie.pdf",
    title     = "Pédagogie et techniques d'animation de formation",
    module    = "M2 – CCP1",
    ccp       = "CCP1 – Animer des actions de formation à la conduite",
    objectifs = [
        "Maîtriser les principes de l'andragogie (éducation des adultes)",
        "Choisir et appliquer les méthodes pédagogiques adaptées à chaque situation",
        "Adopter la posture de formateur bienveillant et professionnel",
    ],
    sections  = [
        ("Les fondements de l'andragogie", [
            "L'andragogie est la science de l'éducation des adultes, formalisée par Malcolm Knowles (1970). Elle repose sur 6 principes : le besoin de savoir, l'image de soi autonome, l'expérience antérieure, la disposition à apprendre, l'orientation vers l'action et la motivation intrinsèque.",
            "Contrairement à l'enfant, l'adulte apprend mieux quand il comprend POURQUOI il doit acquérir une compétence et qu'il en perçoit l'utilité pratique immédiate dans sa vie professionnelle.",
        ]),
        ("Les méthodes pédagogiques actives", [
            "Méthode démonstrative : le formateur montre, l'apprenant reproduit. Particulièrement adaptée aux gestes techniques (positionnement des mains sur le volant, vérification des rétroviseurs).",
            "Méthode interrogative : le formateur guide par des questions ouvertes. Favorise la réflexion et l'appropriation. Exemple : 'Que feriez-vous si vous voyiez un piéton traverser sans regarder ?'",
            "Méthode active : mises en situation réelles, jeux de rôle, études de cas. L'apprenant est acteur de son apprentissage et construit lui-même ses savoirs.",
        ]),
        ("La posture du formateur ECSR", [
            "Le formateur adopte une posture de coach plutôt que de juge. Il valorise systématiquement les réussites avant de corriger les erreurs (technique du sandwich feedback : positif / à améliorer / positif).",
            "En véhicule : voix posée et calme, même en situation de risque. Gestes limités et précis. Regard bienveillant. Ne jamais crier ni humilier l'apprenant.",
        ]),
        ("Gestion des apprenants en difficulté", [
            "Identifier les signes d'anxiété au volant : crispation des mains, respiration accélérée, silence inhabituel, regards fréquents vers le formateur.",
            "Adapter la réponse pédagogique : ralentir le rythme, simplifier temporairement les consignes, revenir sur un exercice maîtrisé pour restaurer la confiance, puis reprendre progressivement.",
        ]),
    ]
)

# ── PDF 3 : CCP2 M4 – Gestion administrative ─────────────────────────────────
make_pdf(
    filename  = "CCP2-M4-gestion-administrative.pdf",
    title     = "Gestion administrative et financière de l'école de conduite",
    module    = "M4 – CCP2",
    ccp       = "CCP2 – Veille professionnelle et développement de l'activité",
    objectifs = [
        "Connaître les obligations administratives d'une auto-école conforme",
        "Comprendre le fonctionnement du CPF et de la certification Qualiopi",
        "Maîtriser les bases de la protection des données (RGPD)",
    ],
    sections  = [
        ("Les obligations administratives", [
            "L'auto-école doit tenir à jour : le registre des élèves, les contrats de formation signés, les feuilles d'émargement horodatées, les bilans pédagogiques individuels et les conventions de formation.",
            "En cas d'inspection DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités), ces documents doivent être présentés dans les 48 heures. L'absence de feuilles d'émargement peut entraîner le retrait de l'agrément et le remboursement des financements perçus.",
        ]),
        ("Le financement CPF", [
            "Depuis 2020, le permis de conduire B est éligible au CPF (Compte Personnel de Formation) via la plateforme Mon Compte Formation (moncompteformation.gouv.fr). Le montant mobilisable est plafonné à 1 700 €.",
            "Démarche côté apprenant : créer son compte sur la plateforme, sélectionner la formation, valider l'inscription avec l'auto-école. Le paiement est versé directement à l'établissement.",
            "Condition impérative : l'auto-école doit être certifiée Qualiopi pour accéder au financement CPF.",
        ]),
        ("La certification Qualiopi", [
            "Qualiopi est une certification qualité obligatoire depuis le 1er janvier 2022 pour tout organisme de formation souhaitant bénéficier de financements publics ou mutualisés : CPF, OPCO, Pôle Emploi, Régions.",
            "Elle est délivrée après audit par un organisme certificateur accrédité (AFNOR, Bureau Veritas, ICPF, etc.). Durée de validité : 3 ans avec un audit de surveillance intermédiaire à 18 mois.",
            "Le référentiel Qualiopi comporte 7 critères et 32 indicateurs évalués lors de l'audit, couvrant la communication, la conception des formations, l'accompagnement, l'évaluation et la veille réglementaire.",
        ]),
        ("Protection des données personnelles – RGPD", [
            "L'auto-école collecte des données personnelles sensibles : photo d'identité, résultats d'examens, coordonnées, données de santé (visite médicale). Le RGPD (Règlement Général sur la Protection des Données) impose leur traitement rigoureux.",
            "Actions obligatoires : tenir un registre des traitements, informer les apprenants de leurs droits (accès, rectification, suppression), sécuriser les accès informatiques, définir des durées de conservation et les respecter.",
            "En cas de violation de données : obligation de notifier la CNIL dans les 72 heures. Des amendes pouvant aller jusqu'à 4% du chiffre d'affaires annuel mondial sont prévues.",
        ]),
    ]
)

print(f"\n3 PDF generes dans : {OUTPUT_DIR}")
