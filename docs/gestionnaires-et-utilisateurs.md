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

## Avancement réel

- **Existant en prototype** : CRUD de bénéficiaires sur licence dans l'espace client et l'administration, types OGIPEN/SAM/AD déclaratifs, contrôle propriétaire et quota, transactions.
- **Non encore opérationnel** : délégation de gestionnaires d'organisation, authentification/liaison cryptographique des identités SAM/AD, contrôle des appareils, tableau unifié et ergonomique, signature, audit complet, API de validation sécurisée.
- La version actuelle ne prouve **pas encore** l'identité Windows ou AD d'un utilisateur inscrit. Ne pas la considérer comme un système de licences sécurisé en production.

**Décision : cette fonction est un bloc obligatoire avant mise en production et ne doit pas disparaître au profit des seuls écrans logiciels / modules / WooCommerce.**
