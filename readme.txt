=== Pratcom – Études de cas ===
Contributors: pratcom
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPLv2 or later

Type de contenu « Études de cas », séparé des articles du blogue.

== Description ==

* Menu « Études de cas » juste sous « Articles », même écran d'édition qu'un article (titre, contenu, extrait, image mise en avant, auteur, commentaires, révisions).
* Secteurs (comme les catégories) et Services (comme les étiquettes), propres aux études de cas.
* Fiche projet : client, site web, logo, période, résultats clés (chiffre + description, 8 max), témoignage.
  Éditeur de blocs : panneau « Fiche projet » dans la barre latérale. Éditeur classique : boîte sous le contenu.
* Adresses : /etudes-de-cas/, /etudes-de-cas/secteur/transport/, /etudes-de-cas/service/seo/ (modifiables dans Études de cas > Réglages).
* Gabarits fournis :
  - thème classique : templates/classic/single.php et archive.php ;
  - thème blocs (Pratcom Base, TT25) : gabarits enregistrés, modifiables dans l'éditeur de site.
  Le thème peut les remplacer (single-etude_de_cas.php / archive-etude_de_cas.php, ou templates/single-etude_de_cas.html).
  Mode « thème » disponible dans les réglages (le thème gère tout l'affichage).
* Blocs : Fiche projet, Études de cas (grille), Filtre par secteur, Études connexes, Carte d'étude.
* Codes courts (éditeur classique, widgets) :
  [pratcom_etudes_de_cas nombre="3" secteur="transport" service="seo" colonnes="3" tri="date"]
  [pratcom_fiche_projet parts="facts,results,testimonial" id="123"]
* Données structurées : enrichit l'Article de Yoast / Rank Math (client en « about », secteurs, services), ou produit son propre JSON-LD.
* WPML : wpml-config.xml fourni (type, taxonomies, champs). Traduire l'identifiant d'URL dans WPML > Réglages > Traduction des types de publication.
* Traductions : fr_CA, fr_FR (chaînes sources en anglais).

== Mises à jour ==

Le plugin se met à jour comme une extension de WordPress.org, à partir de la branche main du dépôt public github.com/pratcom/pratcom-etudes-de-cas. Aucune clé ni configuration. Une nouvelle version = l'en-tête Version augmenté sur main.

== Désinstallation ==

La suppression de l'extension retire seulement ses réglages. Les études de cas, secteurs et services restent en base.

== Changelog ==

= 1.1.2 =
* Après une mise à jour, l'avis « Nouvelle version disponible » ne reste plus affiché pour la version qu'on vient d'installer (la version installée est lue dans le fichier, pas dans le code encore chargé).

= 1.1.1 =
* « Compatible jusqu'à » tient compte des versions correctives (7.1 couvre 7.1.2) : plus d'avertissement « pas testée » dans la fenêtre de détails.

= 1.1.0 =
* Mises à jour directes depuis WordPress (dépôt GitHub public pratcom/pratcom-etudes-de-cas) : avis « Mise à jour disponible », mises à jour automatiques possibles, lien « Vérifier les mises à jour ».
* Traductions en fichiers .l10n.php (format natif WordPress 6.5+) au lieu de .mo.

= 1.0.2 =
* Nouvelle étude : contenu de départ en blocs natifs, 4 sections H2 (Le contexte, Le défi, La solution, Les résultats) avec ancres neutres #section-1 à #section-4, paragraphes et listes.
* Réglage « Étude de cas (page) » : automatique (thème blocs → single.html du thème, thème classique → gabarit du plugin), thème, ou plugin.
* Quand le thème affiche l'étude, la fiche projet est insérée au-dessus du contenu et le témoignage en dessous (activé par défaut). Aucun titre ajouté, rien n'est enregistré dans le contenu.

= 1.0.1 =
* Gabarits blocs : marges haut/bas de la zone principale (spacing 60/70, comme les pages du thème) ; parties d'en-tête et de pied sans balise imposée.

= 1.0.0 =
* Première version.
