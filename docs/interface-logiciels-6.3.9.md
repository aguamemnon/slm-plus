# SLM Plus Multi-Software 6.3.9 — administration orientée logiciels

Ce lot est un prototype d'interface, testé avec la syntaxe PHP et des doubles des hooks
WordPress. Recette réelle sur site WordPress/WooCommerce requise avant production.

## Menu simplifié

- **SLM Plus → Logiciels** : entrée principale pour le catalogue.
- Le menu indépendant **Modules logiciels** est volontairement masqué.
- Un module reste éditable en cliquant sur son nom depuis **Modifier le logiciel**.
- **SLM Plus → Gestion des licences** : deux onglets intégrés dans
  le même écran : **Licences logicielles** (version majeure, modules, places
  occupées et date d'expiration) et **Licences classiques** (ancienne liste
  et anciens filtres SLM conservés).
- La page de détail administratif d'une licence multi-logiciels reste accessible
  via le bouton **Ouvrir / utilisateurs** depuis la gestion des licences, mais
  l'entrée redondante **Licences clients** n'est plus visible dans le menu.
- **SLM Plus → Offres WooCommerce** : accès possible à toutes les offres,
  même lorsque la fiche logiciel affiche ses propres offres liées.

## Liste des logiciels

La liste affiche directement :
1. **Logiciel** : nom de la fiche WordPress.
2. **Version actuelle** : version X.Y.Z définie dans la fiche.
3. **Modules associés** : noms des modules rattachés au logiciel.
4. **Licences SLM Multi** : nombre de nouvelles licences dans les tables
   Multi-Software, **sans inclure** les anciennes licences classiques SLM Plus.

La **Modification rapide** WordPress est supprimée pour ce type de contenu,
ainsi que l'édition groupée générique. Les champs *Date*, *Mot de passe* et
*Privée* qu'elle exposait ne sont pas des propriétés de licence.

## Fiche d'un logiciel

Sous la description du logiciel :
- Version courante (ex. 1.8.0 ou 2.0.0).
- Modules rattachés avec accès à leur éditeur.
- Ajout immédiat d'un module via son nom, puis **Mettre à jour**.
- Création d'un module avec description.
- Offres WooCommerce associées avec type (achat, renouvellement, module,
  sièges, mise à niveau majeure), version, nombre de places, durée et
  validité de la configuration.
- Bouton **Créer une offre pour ce logiciel**, qui pré-sélectionne le logiciel
  dans le formulaire du produit WooCommerce.

## Sémantique des versions

Une licence est liée à sa **version majeure**. Renouveler une v1 reste en v1 ;
un upgrade payé v1 → v2 migre explicitement le contrat sans perdre les utilisateurs
et sans créer une seconde licence. Le changement de version de la fiche logiciel
ne migre jamais les droits clients existants.

## Ce qui reste

- Validation de l'interface sur une installation WordPress/WooCommerce réelle.
- Mise en forme cohérente de la page de gestion historique SLM et de la fiche
  licence (panneaux, filtres, recherche et navigation).
- Workflow de migration manuelle audité pour les toutes premières licences
  Multi-Software sans version majeure (0).
- Licences signées ECDSA, identité nominative et vérification côté client.
- Tests paiement, remboursement, réentrance et WooCommerce Checkout Blocks.

Toutes les modifications sont dans `feature/multi-software-licensing`,
`master` reste intacte.
