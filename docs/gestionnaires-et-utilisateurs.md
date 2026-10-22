# SLM Plus Multi-Software — Gestionnaires clients et utilisateurs nominatifs

**Référence fonctionnelle obligatoire du fork**, distincte du catalogue des logiciels, modules, versions majeures et des permissions métier de chaque logiciel.

## Deux types de personnes

### 1. Gestionnaires de licences côté client
- L'acheteur (compte WordPress/WooCommerce) possède une organisation cliente et des licences.
- Il peut déléguer la gestion des licences de son organisation à des **gestionnaires autorisés** sans partager ses identifiants WordPress.
- Les gestionnaires voient uniquement **leurs licences et leurs utilisateurs**, jamais ceux des autres organisations.
- Les permissions « consulter », « gérer les utilisateurs », « gérer les appareils » et « acheter/renouveler » doivent être séparables.
- Toutes les actions sensibles sont auditées (qui, quoi, quand, origine).
- L'administrateur global SLM Plus peut consulter et intervenir sur les organisations, leurs licences, modules et comptes.

### 2. Utilisateurs bénéficiaires du logiciel
- Un bénéficiaire est une personne nominative inscrite **sur une licence logicielle** et **dans le quota de sièges actifs**.
- Son inscription **n'implique pas** la création d'un compte WordPress ni un rôle administrateur sur le portail.
- Pour chaque bénéficiaire : identité OGIPEN/portail, nom affiché, e-mail éventuel, statut actif/suspendu, date d'enrôlement, appareils liés, dernière vérification.
- **Méthodes d'identité** : compte du portail, domaine Active Directory local, compte Windows SAM local ; à terme côté Linux, PAM/domaine selon environnement.
- Pour SAM/AD, ne **jamais** utiliser un simple nom d'utilisateur envoyé par le client comme preuve d'identité : liaison sûre et préalable à un utilisateur du portail, identifiant stable qualifié par domaine/organisation/autorité (et mécanisme cryptographique à définir).
- Ne jamais stocker les mots de passe Windows/AD dans WordPress/SLM Plus.

## Parcours de gestion

Dans **Mon compte → Mes logiciels et licences** :
1. Sélectionner le logiciel, la version majeure et la licence.
2. Voir places utilisées / disponibles, modules souscrits, expiration, appareils.
3. Ajouter, modifier, suspendre, réactiver ou supprimer un bénéficiaire.
4. Enrôler/associer le compte local/AD/portail par preuve vérifiable.
5. Dépasser le quota entraîne un refus serveur, y compris lors de requêtes simultanées.
6. Le remplacement d'un utilisateur libère sa place selon la politique contractuelle (anti-abus et audit).

Dans **SLM Plus → Gestion des licences**, depuis le détail de la licence :
1. Vue administrateur des bénéficiaires et gestionnaires.
2. Suspension/révocation et gestion des appareils.
3. Historique des renouvellements, upgrades majeurs, achats de modules et changement de quotas.
4. Intervention manuelle dûment journalisée.

## Condition d'ouverture d'un logiciel

Une session de logiciel est admise **uniquement** si :
- l'utilisateur a prouvé son identité et son compte est lié à un bénéficiaire actif ;
- l'organisation a une licence signée valable pour **ce logiciel et cette version majeure** ;
- l'utilisateur est encore inscrit sur la licence, dans la limite des sièges ;
- les modules demandés sont couverts par la licence ;
- pour OGIPEN/Progelec, les droits métier et les projets sont validés **séparément dans `progelec`**.

Les informations envoyées au poste client doivent être signées (droits minimaux + expiration + bénéficiaire + appareil) et compatibles avec une fenêtre hors ligne bornée ; une simple clé ou un JSON modifiable ne suffit pas.

## Avancement réel — lot de délégation (6.3.10)

- **Réalisé en prototype** : CRUD de bénéficiaires, quota contrôlé par transaction, gestionnaires délégués à l'échelle du **portefeuille d'un acheteur WordPress**, liste des bénéficiaires et des licences accessibles, contrôle serveur par permission.
- Dans **Mon compte → Mes logiciels et licences**, le propriétaire sélectionne une licence puis accorde à un autre **compte WordPress existant** l'un de deux rôles :
  - **Lecteur** : consulter les licences du même acheteur et leurs bénéficiaires ; **aucune modification**.
  - **Gestionnaire des utilisateurs** : consulter et ajouter, modifier, désactiver ou supprimer les bénéficiaires dans la limite des places ; pas d'achat, ni de changement de modules, ni de gestion des autres gestionnaires.
- Le propriétaire ou l'administrateur global peut révoquer immédiatement la délégation. La vérification se fait depuis la base à chaque opération ; aucune confiance dans la présence d'un bouton HTML.
- L'admin global voit les gestionnaires associés à une licence depuis son détail. Les ajouts/modifications/révocations des gestionnaires et des bénéficiaires écrivent dans **`slmms_audit`** (identifiants et action, sans mots de passe).
- Le périmètre actuel est **un compte acheteur et toutes les licences qu'il détient**, et **non encore** une entité entreprise multi-acheteurs ou multi-filiales. La séparation interclients est testée.
- Les gestionnaires ne consomment **pas** une place de licence par leur seul rôle administratif. Un gestionnaire qui utilise le logiciel devra aussi être déclaré bénéficiaire.

### Limites essentielles

- La délégation requiert un **compte WordPress déjà enregistré** ; invitations, rattachement d'entreprises, rôles multi-organisations et délégation d'achat restent à concevoir.
- **Comptes du portail (6.3.12)** : une vérification nominative existe désormais par code à usage unique envoyé à l'adresse e-mail, confirmé par le **compte WordPress du bénéficiaire**. Elle relie la place à l'identifiant WordPress et marque le bénéficiaire comme vérifié ; voir [enrôlement du compte portail](enrolement-identite-portail.md). Elle ne délivre pas encore une licence client utilisable.
- **SAM / AD** : identification toujours déclarative, sans preuve cryptographique du compte local/domaine ; ils restent non vérifiés. Ne jamais donner réellement accès à un logiciel sur la base d'un seul `subject`.
- L'enrôlement d'un poste, la signature numérique de l'autorisation, la révocation des appareils, la délégation fine « gérer uniquement tels logiciels » et un audit administrateur exhaustif restent à développer.
- **Ne pas déployer en production** avant une recette WordPress/WooCommerce + données réelles et un audit de sécurité.

**Décision : cette fonction est un bloc obligatoire avant mise en production et ne doit pas disparaître au profit des seuls écrans logiciels / modules / WooCommerce.**
