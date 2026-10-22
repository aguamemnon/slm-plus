# SLM Plus Multi-Software — Contrats par version majeure (v0.2, prototype)

## Fonctionnement commercial confirmé

- Une fiche **Logiciel** possède une version actuelle `1.8.0`, `2.0.0`, etc., et
  affiche directement les modules associés. On peut ajouter un module depuis sa fiche.
- Une licence est émise pour **une version majeure déterminée** :
  - licence v1 → versions `1.x` ; les correctifs et versions mineures sont compris ;
  - licence v2 → versions `2.x` ;
  - passer le logiciel de `1.8.0` à `2.0.0` ne convertit pas les licences clients.
- **Renouvellement v1 → v1** : repousse l'expiration, sans changement de version.
- **Upgrade v1 → v2** : achat spécifique WooCommerce, vérifiant
  le propriétaire, le logiciel, la version source et la version cible.
  Après confirmation du paiement, **la même licence** est migrée vers la v2.
- L'upgrade conserve la clé de licence, les utilisateurs nominatifs et le quota,
  les modules liés au logiciel et la date d'expiration (sauf si la formule inclut
  une prolongation). Une formule peut aussi ajouter des modules.
- **Upgrade + renouvellement** : si la licence est expirée, la durée de
  renouvellement est obligatoire et démarre à la date de paiement ;
  si elle est valide, les jours restants sont préservés.
- **Pas de rétrogradation** et **pas de v1 → v2 implicite** depuis une offre
  de simple renouvellement v1. Le traitement de paiement refuse également
  un upgrade v2 → v2 déjà appliqué.
- L'offre explicite possède un champ « Version majeure autorisée » dans
  WooCommerce. Ne pas vendre une offre v2 avant publication de la v2 du logiciel.

## Où configurer

1. **SLM Plus → Logiciels → Modifier** :
   `Version actuelle` et liste de modules liés au logiciel.
2. **WooCommerce → Produits → Modifier (Produit simple)** :
   - Offre SLM Multi : **Nouvelle licence**, **Renouvellement**,
     **Mise à niveau majeure**, ou autres ;
   - Logiciel cible ;
   - Version majeure (ex. `2`) ;
   - Nombre de places, modules, durée optionnelle pour une mise à niveau.
3. La page produit de mise à niveau demande **la clé cible avant l'ajout au panier**.
   La commande payée actualise la licence source, sans en créer une seconde.
4. **SLM Plus → Gestion des licences** :
   premier tableau des nouvelles licences avec logiciel, version,
   modules, places occupées/achetées et échéance, suivi de la liste SLM historique.
5. **Mon compte WooCommerce → Mes logiciels et licences** :
   version et utilisateurs autorisés.

## Persistance et migration

La table `slmms_licenses` reçoit la colonne
`major_version SMALLINT UNSIGNED NOT NULL DEFAULT 0` :
- les nouvelles licences ont une majeure explicitement définie ;
- les licences de test multi-logiciels antérieures à v0.2 ont la majeure **0**
  (version inconnue), pour **ne pas leur attribuer v1 arbitrairement** ;
- elles requièrent un **classement manuel audité** ou une migration validée avant
  de pouvoir être renouvelées/mises à niveau via le nouveau circuit ;
- les licences historiques `SLM Plus` restent dans leurs anciennes tables :
  elles ne sont **pas migrées automatiquement**.

Si une licence devient v2, son droit v1 n'est pas conservé en parallèle
dans ce prototype. Une stratégie de rétrocompatibilité ou de licences
multiples devra être définie séparément si nécessaire.

## Vérifications

La CI PHP 7.4, 8.2, 8.3 teste les versions, l'achat ciblé,
le rejet des mauvaises licences, les scénarios de renouvellement et
de mise à niveau. Le test de WordPress/WooCommerce avec vrai MySQL et
un paiement simulé reste à effectuer.

**Ne pas déployer directement en production.** La signature numérique du
manifeste de licence, la liaison authentifiée SAM/AD et la politique de
révocation/retour en arrière ne sont pas encore complètes.
