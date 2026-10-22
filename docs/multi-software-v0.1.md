# SLM Plus fork — Multi-Software v0.1 (branche de développement)

## Objectif

Gérer **plusieurs logiciels** (OGIPEN et futurs logiciels) avec leurs modules, et
commercialiser dans WooCommerce la création **ou l'évolution** d'une licence.

Le code est intégré directement dans le fork `aguamemnon/slm-plus`, sans modifier
les tables historiques de SLM Plus. Un nouveau namespace SQL `wp_slmms_*`
(préfixe WordPress réel variable) sépare les licences multi-logiciels.

**Statut : prototype fonctionnel de branche. PHP lint et tests de calcul réussis.
Pas encore validé sur une installation WordPress/WooCommerce complète. Ne pas
installer directement sur le site de production.**

## 1. Gestion des logiciels et modules

Back-office WordPress :
- **Logiciels** → Ajouter un logiciel (type `slm_software`).
- **Logiciels > Modules logiciels** → Ajouter un module lié à un logiciel parent.
- Les modules sont des identifiants indépendants du nom affiché. Un module
  ne peut pas être attribué à une licence d'un autre logiciel.

Dans WooCommerce, créer un **produit simple/virtuel**, avec tarif normal, puis
renseigner le panneau **Général** :
- *Offre logicielle (SLM Multi)* : `Nouvelle licence`, `Prolonger`,
  `Ajouter des modules`, `Prolonger et ajouter des modules` ou
  `Ajouter des utilisateurs` ;
- le logiciel concerné ;
- les modules inclus/ajoutés ;
- nombre de places initiales ou additionnelles ;
- durée (en jours) pour les opérations avec prolongation.

Exemples :
- OGIPEN Étude + Rapports / 10 utilisateurs / 365 jours → **Nouvelle licence** ;
- OGIPEN Chantier → **Ajouter des modules**, avec la licence existante ;
- renouveler 365 jours → **Prolonger une licence**, sans écraser les jours non consommés ;
- 5 places supplémentaires → **Ajouter des utilisateurs**.

## 2. Commerce et sécurité des commandes

Pour toutes les opérations **autres que la création**, la page produit demande
une clé de licence cible **avant l'ajout au panier**, contrôlée côté serveur :
- authentification WordPress requise ;
- même logiciel ;
- licence appartenant au compte WooCommerce qui commande ;
- licence active ou précédemment expirée (pas bloquée/suspendue) ;
- le module doit appartenir au logiciel.

La v0.1 impose **un produit multi-logiciels par commande**, sans mélange avec
un autre produit. Cette contrainte volontaire simplifie l'attribution et évite
la collision des anciens hooks SLM Plus.

La commande mémorise une photographie des paramètres **côté serveur** dans
les métadonnées de ligne WooCommerce (API objet, compatible avec le modèle
de stockage HPOS côté commandes). Seul un paiement constaté par WooCommerce
(`is_paid()` **et** `get_date_paid()`) permet l'attribution.

La table **`slmms_order_items`** contient un identifiant d'élément de commande
unique : plusieurs callbacks WooCommerce pour le même élément ne doivent
pas cumuler plusieurs fois la durée, les modules ou les places. La transaction
MySQL englobe création/mise à jour et journal. La base doit utiliser InnoDB.

Les deux anciens hooks de génération SLM Plus sautent les commandes identifiées
comme multi-logiciels, afin de ne pas émettre une **seconde** licence.

La v0.1 ne gère pas encore remboursement/annulation après paiement ni les
commandes multiples, les abonnements récurrents, les coupons spéciaux, les
produits variables ou les nouveaux flux Checkout Blocks / Store API : faire
une recette dédiée avant utilisation commerciale.

## 3. Gestion des utilisateurs côté client

Après connexion WooCommerce : **Mon compte > Mes logiciels et licences** :
- liste des licences et modules achetés (cette première liste montre la licence,
  les places et l'échéance ; détail modules à améliorer) ;
- gestion de la liste des bénéficiaires d'une licence ;
- déclarer un compte **OGIPEN, SAM Windows local ou AD local**, avec identifiant
  unique, nom affiché et email optionnel ;
- modifier, suspendre/réactiver ou supprimer un bénéficiaire ;
- la somme des bénéficiaires actifs ne dépasse pas le nombre de places ;
- contrôle du propriétaire sur **toute lecture et écriture** ;
- contrôle de concurrence sur la ligne licence pour protéger le quota.

Un bénéficiaire n'est **pas forcément un compte WordPress** : son inscription
donne uniquement le droit commercial potentiel d'utiliser le logiciel.
La **preuve d'identité SAM/AD** et le lien cryptographique entre bénéficiaire et
session logicielle restent à implémenter séparément, tout comme les permissions
métier `progelec` pour OGIPEN.

## 4. Gestion administrative

**Logiciels > Licences clients** :
- consulter les licences, logiciels, propriétaires, places et dates ;
- gérer les utilisateurs attachés à chaque licence ;
- activer, suspendre ou bloquer une licence ;
- aucune modification automatique d'une licence sans nouvelle commande payée,
  hors décisions administratives explicites.

Un journal d'audit exhaustif des interventions manuelles et des transferts de
licence reste à créer avant production.

## 5. Installation de test

1. Cloner la branche `feature/multi-software-licensing` du fork dans une
   installation WordPress **de test** ; ne pas mettre à jour le site en direct.
2. Activer WooCommerce, puis activer SLM Plus.
3. Visiter l'administration WordPress : les trois tables multi-logiciels seront
   installées via migration/activation.
4. Créer un logiciel, des modules et un produit **simple** WooCommerce pour
   chaque formule souhaitée.
5. Vérifier la création de licence avec une commande réellement marquée payée,
   puis renouvellement sur la même licence.
6. Dans **Réglages > Permaliens**, enregistrer une fois les réglages pour que
   l'endpoint Mon compte > Mes logiciels et licences soit reconnu.
7. Tester un second utilisateur WordPress : il ne doit jamais voir ou modifier
   une licence appartenant au premier.
8. Tester un quota rempli (ex. 2/2), et la désactivation/réactivation d'un siège.

## 6. Priorités avant production

1. **Signature ECDSA des droits** côté serveur, délivrance d'un manifeste
   minimal (logiciel, modules, places, validité) sans jamais exposer le secret
   de signature aux clients Windows/Linux. Le JSON exporté de l'ancien SLM
   n'est **pas** une preuve signée.
2. **Liaison forte des identités** du poste SAM/AD/portail aux bénéficiaires.
3. Revocation, renouvellements automatiques, webhooks sûrs, remboursements,
   audit des opérations, preuve de paiement et contrôle de course en conditions réelles.
4. Tests WordPress/WooCommerce/HPOS, Checkout Blocks, scénarios de commandes.
5. Administration multi-entreprises et délégation (v0.1 : un compte acheteur
   propriétaire de ses licences, pas encore plusieurs gestionnaires pour une organisation).

## Vérification PHP automatisée

`.github/workflows/slm-multisoft.yml` : lint PHP 7.4/8.2/8.3 et tests de calcul
des nouveaux droits lors de créations, ajouts, renouvellements et dépassements.

## Isolement du produit tiers

- `master` inchangée.
- Licence du fork : vérifier le fichier LICENSE.md et les métadonnées GPL
  divergentes du plugin original avant distribution.
- Les nouvelles licences multi-logiciels restent dans les tables
  `slmms_licenses`, `slmms_users`, `slmms_order_items`.
- Anciennes licences SLM Plus : pas de migration automatique ; à concevoir.
