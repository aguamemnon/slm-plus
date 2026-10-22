# Identité des bénéficiaires — état de vérification (SLM Multi 6.3.11)

Un bénéficiaire inscrit sur une licence **n'a pas encore prouvé** qu'il est
le titulaire du compte Windows SAM, de l'identité AD ou du compte portail.

## Données

La table `slmms_users` possède désormais
`identity_verified TINYINT(1) NOT NULL DEFAULT 0`.
La migration est gérée par `dbDelta` via le compteur de schéma
`SLMMS_VERSION = 0.4.0`.

- **Déclaré / en attente** : le gestionnaire a enregistré une identité ; elle
  ne doit pas permettre de délivrer un jeton de connexion au logiciel.
- **Vérifié** : preuve d'identité obtenue par un mécanisme d'enrôlement sûr
  (non implémenté à ce stade).
- Toute modification du fournisseur d'identité ou du sujet réinitialise le
  statut à **en attente**.
- Un bénéficiaire peut être actif commercialement tout en restant non
  vérifié : ces deux statuts sont volontairement indépendants.

## Écrans et droits

L'écran des bénéficiaires, accessible à l'acheteur, au gestionnaire délégué
muni de `manage_users` et à l'administrateur, montre la vérification.
L'interface WordPress **n'offre pas de bouton « identité vérifiée »** :
l'enrôlement sécurisé sera responsable de changer cet état.

## Prochaine étape obligatoire

1. Concevoir le parcours de liaison initiale du bénéficiaire à son compte
   portail ou compte local/AD, sans envoyer de mot de passe local au site.
2. Valider la preuve côté service d'émission de licences avant de mettre
   `identity_verified = 1` et produire un jeton nominatif signé.
3. Inclure organisation, logiciel, version majeure, modules, bénéficiaire,
   poste, expiration et renouvellement/revocation dans le contrôle.
4. Journaliser les preuves et révocations ; tests d'intégration WordPress
   et client C# avec un véritable service de validation.

Ne pas prendre le drapeau SQL seul comme une **preuve cryptographique**.
La branche est un prototype ; aucune authentification SAM/AD distante
n'est opérationnelle.
