# SLM Plus Multi-Software 6.3.12 — Enrôlement nominatif vérifiable

## Objectif

Un gestionnaire ne peut plus considérer la simple saisie d'un nom de compte
comme une preuve d'identité. La procédure ci-dessous ajoute une **preuve
pour les comptes de portail WordPress uniquement**. Elle n'autorise PAS
l'authentification SAM/Active Directory local, qui nécessitera un composant
d'enrôlement adapté sur le poste Windows et une liaison cryptographiquement sûre.

## Parcours gestionnaire

1. L'administrateur SLM Plus ou le gestionnaire du compte client ouvre la
   licence dans **SLM Plus > Gestion des licences**, ou
   **WooCommerce > Mon compte > Mes logiciels et licences**.
2. Il déclare l'utilisateur nominatif :
   - `Type de compte = OGIPEN` ;
   - `Adresse e-mail` obligatoire ;
   - nom affiché et identifiant unique.
3. La place est réservée dans le quota `actifs / places`, mais le statut de
   l'identité reste **En attente de vérification**.
4. Le gestionnaire sélectionne **Envoyer un code de vérification**.
   WordPress envoie un code à usage unique de dix caractères à l'adresse du
   bénéficiaire. Le code expire après **20 minutes** ; renvoi limité à
   une fois toutes les **120 secondes** par bénéficiaire.
5. Le code ne figure ni dans les URLs, ni dans les tables sous forme lisible,
   ni dans le journal d'audit : seul un HMAC SHA-256 lié au bénéficiaire est
   conservé provisoirement côté serveur.

## Parcours bénéficiaire

1. La personne utilise **son propre compte WordPress**, dont l'adresse e-mail
   doit correspondre exactement à celle déclarée sur la licence.
2. Après connexion, ouvre **Mon compte > Vérifier mon identité logicielle**
   (`/my-account/software-identity/` selon les permaliens WordPress).
3. Elle saisit le code envoyé par mail : le serveur contrôle le compte
   WordPress connecté, le code HMAC en temps constant, la date d'expiration,
   le statut de la licence, le nombre de places et le bénéficiaire actif.
4. Maximum **cinq tentatives erronées** par émission. En cas de réussite :
   `identity_verified=1`, `identity_wp_user_id=<ID WordPress>`,
   `identity_verified_utc` renseigné ; le code est effacé.
5. Un compte WordPress ne peut pas vérifier deux places de la même licence.
6. La preuve disparaît lors d'un changement de fournisseur, d'identifiant,
   d'e-mail ou d'une suspension, et devra être rétablie par invitation.
7. Une licence expirée, bloquée ou suspendue ne peut plus être vérifiée.

## Sécurité et périmètre

- Le gestionnaire client doit avoir `manage_users` sur le portefeuille ;
  un gestionnaire `viewer` ne peut pas envoyer de codes ni modifier
  les bénéficiaires. Le serveur revalide les permissions à chaque POST.
- Les formulaires sont protégés par nonces WordPress (anti-CSRF).
- Les modifications et contrôles concurrents utilisent des transactions SQL
  et verrouillent la licence puis le bénéficiaire (InnoDB requis).
- Les événements `identity_invited` et `identity_verified` sont audités
  sans enregistrer le code ou les secrets.
- **La preuve porte sur la connexion WordPress et l'e-mail du bénéficiaire,**
  et non sur un compte Windows, ni sur l'usage effectif du logiciel.
- Les identités SAM/AD restent déclarées **non vérifiées**, même si un e-mail
  leur est associé. Aucune API ne doit les accepter comme authentifiées
  tant qu'une preuve locale forte n'a pas été implémentée.
- **Le client C# et les licences signées ECDSA ne sont pas encore raccordés**.
  `identity_verified` ne constitue pas seul une autorisation d'exécution.

## Avant installation

- Tester sur une copie WordPress + WooCommerce, avec comptes utilisateurs de test.
- Configurer **wp_mail** / SMTP réellement fonctionnel.
- Après mise à jour, ouvrir l'administration pour permettre la migration de
  `slmms_schema_version = 0.5.0` (colonnes supplémentaires des bénéficiaires).
- Dans **Réglages > Permaliens**, enregistrer une fois pour exposer le nouveau
  point `software-identity` dans Mon compte.
- Vérifier : vrai destinataire, mauvais compte, mauvais code, cinq essais,
  renvoi bloqué avant 120 s, expiration, révocation, changement d'e-mail,
  suspension, dépassement de quota et deux places pour un même compte.
- Ne pas activer sur la boutique de production sans test d'intégration réel
  et audit complet du flux WooCommerce et des anciens traitements SLM Plus.

## À développer ensuite

- Inviter par e-mail les personnes sans compte WordPress, avec procédure
  de création de compte et validation du domaine/client.
- Enrôlement SAM/AD fiable sans liaison Internet entrante vers un annuaire.
- Confirmation des appareils, récupération d'identité, anti-abus.
- Licences et laissez-passer nominatifs signés côté PHP et vérifiés par
  OGIPEN C#/Linux ; expiration et révocation bornée hors ligne.
