# SLM Plus Multi-Software — activation hors ligne (prototype)

## Objectif

Autoriser un poste Windows sans liaison Internet à importer une licence
signée depuis le portail SLM Plus. Les droits logiciels sont **dissociés**
de l'authentification locale de l'utilisateur et des permissions Progelec
sur les projets. Le mécanisme n'ouvre aucun flux réseau depuis le poste.

Le déploiement sur des SI classifiés relève de l'homologation du SI, de ses
procédures de transferts et de ses équipements agréés par l'autorité
compétente : ce plugin et les algorithmes utilisés **ne constituent pas une
homologation ni une certification IGI 1300**.

## Mode opératoire

### A — Sur la machine isolée

1. Dans OGIPEN C# ouvrir **Identification → Activation hors ligne** ou
   **Administration → Application → Licence hors ligne**.
2. Saisir **l'identifiant du logiciel** dans le catalogue SLM Plus
   (l'identifiant numérique du logiciel Progelec dans WordPress). La
   version majeure est celle compilée dans l'application.
3. Cliquer **Exporter la demande (.ogreq)**.
4. Windows crée si nécessaire une clé P-256 personnelle dans le magasin
   CNG Microsoft Software Key Storage Provider, définie non exportable.
5. Le fichier `.ogreq` contient uniquement : version du protocole,
   identifiant logiciel, majeure, clé publique du poste, 32 octets
   aléatoires (défi), signature du défi par la clé privée du poste.
   **Aucun nom de machine, utilisateur, e-mail, projet, IP ou nom de domaine
   AD n'est inclus.**
6. Faire transférer cette demande via le **circuit approuvé pour le SI**.
   En particulier, ne pas brancher une clé USB personnelle ou un support
   amovible non autorisé sur un poste classifié.

### B — Depuis le portail accessible à Internet

1. Le gestionnaire du client ouvre **Mon compte → Mes logiciels et
   licences → Progelec → Informations → Activation hors ligne**.
   L'administrateur peut aussi utiliser **SLM Plus → Gestion des
   licences → ouvrir la licence → Activation hors ligne**.
2. Choisir le bénéficiaire déclaré sur la licence.
3. Charger le `.ogreq`, choisir la durée hors ligne (7, 30, 90, 180 ou
   365 jours), puis **Signer et télécharger (.oglic)**.
4. Le serveur vérifie : demande signée, licence active, quota de places,
   logiciel, majeure, bénéficiaire actif, expiration de licence,
   modules appartenant au même logiciel et autorisation du gestionnaire.
5. **Une seule activation matérielle active par bénéficiaire de licence.**
   Une nouvelle activation pour une clé différente nécessite la révocation
   de l'ancienne activation dans le portail.
6. L'autorité du serveur signe le `.oglic` avec sa **clé privée ECDSA
   P-256** déjà utilisée par les attestations en ligne. Elle demeure sur
   Linux, jamais dans l'archive ou sur le poste.

### C — Retour sur la machine isolée

1. Transférer le fichier `.oglic` signé par les moyens **explicitement
   autorisés**.
2. Cliquer **Importer la licence (.oglic)** dans OGIPEN.
3. Le client vérifie localement, sans réseau : signature, clé publique
   du serveur épinglée, emetteur, version majeure, modules, ID logiciel,
   empreinte de la clé de l'appareil et date d'expiration.
4. Une licence valide est conservée dans le profil utilisateur Windows.
5. La licence ne déverrouille **pas encore les formulaires métier** dans
   ce prototype : l'authentification locale SAM/AD, les droits projet et
   l'activation du chemin de démarrage hors ligne doivent être raccordés.

## Configuration de confiance côté logiciel

La distribution installée par un canal contrôlé doit fournir le fichier
`Assets/SLMMS-Signing-Public.pem` avec la **clé publique authentique**
du serveur. Vérifier son empreinte par canal approuvé avant déploiement.
Un client qui télécharge aveuglément une clé publique avec la licence
n'est pas protégé contre une substitution de signature.

Configurer le **numéro de post du logiciel** :
`%APPDATA%\\OGIPEN\\clientsettings.json` :

```json
{
  "OfflineSoftwareId": 123,
  "ApiBaseUrl": ""
}
```

Le nombre `123` est un **exemple**, à remplacer par l'ID réel Progelec.
Aucune information de projet n'est requise dans la demande.

Pour le prototype C# : la majeure `1`, le slug `progelec` et l'émetteur
`https://www.trueshield.fr` sont **compilés** comme références. Ne pas
changer ces attentes à l'exécution à partir du contenu d'un fichier de
licence non vérifié.

## Validité, révocation et périmètre

- Durée par défaut 30 jours, maximale **365 jours**.
- Expiration réelle = minimum(validité hors ligne, fin du contrat).
- Une nouvelle licence signée est nécessaire après expiration.
- La révocation côté portail **n'affecte pas une copie déjà installée tant
  que cette copie n'a pas expiré**. Limiter les durées des postes sensibles.
- L'empreinte appareil réduit la copie accidentelle vers un autre
  ordinateur, mais le stockage logiciel CNG n'offre **pas d'attestation
  matérielle TPM ni une protection absolue contre un administrateur local
  malveillant**.
- Un utilisateur ayant le contrôle de l'horloge système peut chercher à
  prolonger une licence : protection anti-retour en arrière, horloge fiable
  et éventuelle attestation TPM restent à développer. Le prototype
  **ne convient pas encore à l'application de droits définitifs**.
- Pour les comptes SAM/AD, le bénéficiaire est **attribué par le
  gestionnaire**, mais **aucune preuve de l'identité locale de la personne**
  n'est transmise à WordPress. La vérification Windows doit être assurée
  localement par OGIPEN, sans accès distant à l'annuaire d'entreprise.
- Sur les réseaux classifiés, les données et supports sortants ou
  entrants sont soumis aux règles d'homologation, d'autorisation de
  sortie, de contrôle anti-malware et de traçabilité. Même un fichier
  volontairement minimal doit être approuvé avant export.

## Sécurité et tests

Le test PHP `tests/multisoft/offline-activation.php` couvre :
demande signée, falsification de la demande, logiciel/majeure, quotas,
modules, signature ECDSA DER, limite contractuelle.

Le test portable .NET 10 `src/OGIPEN.Core.Tests/Program.cs` couvre :
demande C#, format JSON, rejet sur autre machine, mauvais émetteur,
autre version majeure, autres modules, signature invalide et expiration.

La recette complète avec un vrai WordPress, Apache, deux comptes
gestionnaires, transfert hors ligne et vraie clé Windows CNG reste à
valider avant mise en production.

## Sécurité du serveur de signature

Le WordPress `trueshield.fr` a précédemment été compromis. Ne **pas**
installer de clé privée opérationnelle avant vérification du nettoyage,
du serveur Linux, des extensions, de la base, des sessions et des
comptes WordPress. Préférer une signature sur une machine ou un service
distinct avec droits minimaux plutôt qu'une clé persistante utilisable
par n'importe quel code PHP du site.

Voir [API en ligne signée](api-entitlements-signees.md) pour la génération
d'une paire de clés de test et son stockage.
