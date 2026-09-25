=== Pratcom – Études de cas ===
Contributors: pratcom
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.0
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
* Assistant IA (WordPress 7 et son client IA, avec « AI Provider for Anthropic », et « AI Provider for OpenAI » pour les images, clés sous Réglages > Connecteurs ; l'extension ne stocke aucune clé) :
  - accès : le propriétaire (mpratte@pratcom.net) et les adresses qu'il ajoute dans la boîte « Accès à l'assistant » (visible par lui seul). Pour ces personnes, le menu Études de cas ouvre l'assistant ; les autres gardent les écrans WordPress habituels ;
  - la page liste toutes les études originales (recherche, pastilles de langue WPML), avec « Nouvelle étude » et un bouton vers l'écran WordPress standard ;
  - 7 onglets, comme l'assistant d'articles : Fiche projet, Texte, Image, Maillage, SEO, Traduction, Publier ; chaque étape lance la suivante ;
  - Fiche projet : titre, langue, client, site, logo, période, secteurs (création possible), services, résultats clés, témoignage ; crée le brouillon avec les 4 sections H2 (ancres #section-1 à #section-4) ;
  - Texte : une étude raconte un vrai projet, l'IA n'invente rien. « Organiser mes notes » range un texte de base dans les sections et corrige la langue, sans ajouter de faits ; une section sans information est marquée « À compléter » ; résultats et citation trouvés dans les notes remplissent la fiche seulement si elle est vide ; aperçu avant « Appliquer ». « Corriger le texte » : fautes et mise en forme sans réécrire (texte, résultats, témoignage), puis les traductions existantes sont refaites. L'éditeur WordPress reste disponible ;
  - Image : une image de la médiathèque (ou téléversée), ou une image générée par IA (16:9, enregistrée en WebP), avec texte alternatif ; partagée avec les traductions et reprise dans Yoast (Facebook, X) ;
  - Maillage : liens proposés vers les articles et les autres études publiés de la même langue, cochés avant insertion ;
  - SEO : champs Yoast (titre, méta description, requête cible, Facebook, X) et slug, relus avant enregistrement ;
  - Traduire (WPML) : titre, texte, extrait, adresse, champs Yoast, période, résultats, témoignage ; client, site, logo et image à la une copiés ; secteurs et services sans équivalent créés dans la langue et liés ; nouvelle traduction en brouillon, traduction existante mise à jour avec son statut ;
  - Publier : vérifications (sections « À compléter », extrait, image, texte alternatif, SEO, tirets, blocs natifs), puis publier, planifier ou garder en brouillon, traductions comprises ;
  - en français, espace insécable devant « : ; ? ! » et dans les guillemets, aucun tiret cadratin. Sans le client IA, rien ne change dans le menu.

== Mises à jour ==

Le plugin se met à jour comme une extension de WordPress.org, à partir de la branche main du dépôt public github.com/pratcom/pratcom-etudes-de-cas. Aucune clé ni configuration. Une nouvelle version = l'en-tête Version augmenté sur main.

== Désinstallation ==

La suppression de l'extension retire seulement ses réglages. Les études de cas, secteurs et services restent en base.

== Changelog ==

= 1.3.0 =
* Assistant IA d'études de cas en 7 onglets (Fiche projet, Texte, Image, Maillage, SEO, Traduction, Publier), sur le modèle de l'assistant d'articles. Voir la description.
* « Organiser mes notes » : un texte de base rangé dans les sections, sans contenu inventé ; sections vides marquées « À compléter ».
* Image à la une : médiathèque ou génération IA (WebP).
* Accès limité au propriétaire et aux adresses ajoutées dans la boîte « Accès à l'assistant » (avant : toute personne qui modifie des articles). Nouveaux fichiers includes/ai-assistant.php et includes/ai-access.php.

= 1.2.0 =
* Outils IA de correction et de traduction (voir la description) : nouvelle page du menu Études de cas, lien « Corriger / traduire » dans la liste WordPress. Fichiers includes/ai*.php, assets/js/ai.js, assets/css/ai.css.

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
