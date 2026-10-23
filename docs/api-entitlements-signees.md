# SLM Plus Multi-Software 6.3.17 — API de droits nominatifs signés

Prototype **non déployé en production**. Ce composant prépare l'autorisation des
clients Windows OGIPEN (et d'un futur client Linux) tout en conservant les
anciennes licences SLM Plus intactes.

## Contrat REST

`POST https://www.trueshield.fr/wp-json/slmms/v1/entitlements/issue`

Contenu `application/json` :

```json
{"license_id":55,"software_id":42,"major_version":1}
```

Réponse 200 **uniquement si tous les contrôles réussissent** :

```json
{"token":"BASE64URL.HEADER.BASE64URL.PAYLOAD.BASE64URL.SIGNATURE",
 "expires_at":1790000000,"key_id":"identifiant-public","valid_for_seconds":3600}
```

Le jeton a **trois segments**, pas quatre ; la ligne d'exemple ci-dessus
illustre les éléments du jeton et non une valeur réelle.

La méthode utilise l'authentification WordPress individuelle (via
**Application Passwords** en HTTPS lors des tests API, ou session WordPress
avec nonce REST pour une intégration web). **Ne jamais utiliser un mot de
passe administrateur partagé ni le secret SQL de Progelec.**

L'API ne renvoie aucun droit lorsque :
- HTTPS ou connexion individuelle manque ;
- la licence n'est pas active, est expirée ou son quota est dépassé ;
- la version majeure et le logiciel demandés ne correspondent pas ;
- aucun bénéficiaire actif, de type `ogipen`, lié au compte WordPress
  et confirmé par code OTP n'est trouvé pour cette licence ;
- deux bénéficiaires vérifiés occupent anormalement cette même place ;
- un module n'existe plus, n'est plus publié ou appartient à un autre logiciel ;
- une signature ne peut pas être créée.

L'API refuse les identités SAM/AD **simplement déclarées**. Leur véritable
enrôlement local doit être développé séparément : le serveur web sur Internet
ne tente jamais de contacter les domaines AD des entreprises.

L'API **ne demande pas la clé de licence comme preuve** : une clé divulguée
ne suffit pas à obtenir un jeton. L'utilisateur doit être identifié, autorisé
et confirmé sur sa propre place.

## Génération et conservation des clés sur Linux

Sur un serveur sain et administré, faire les opérations en root :

```bash
install -d -m 0750 -o root -g www-data /etc/slmms
openssl ecparam -name prime256v1 -genkey -noout -out /etc/slmms/license-signing.key
chown root:www-data /etc/slmms/license-signing.key
chmod 0640 /etc/slmms/license-signing.key
openssl pkey -in /etc/slmms/license-signing.key -pubout \
    -out /etc/slmms/license-signing-public.pem
chmod 0644 /etc/slmms/license-signing-public.pem
```

Dans `wp-config.php` (**le chemin uniquement, jamais le contenu de la clé**) :

```php
define('SLMMS_SIGNING_PRIVATE_KEY_PATH', '/etc/slmms/license-signing.key');
```

Le compte PHP-FPM qui traite ce site doit pouvoir **lire** cette clé.
Vérifier l'identité du pool PHP-FPM et ajuster le groupe si nécessaire.
La clé privée est située hors `/var/www/html` et **n'est jamais conservée
dans la base WordPress, les archives GitHub ou dans le client C#**.

**Important :** ce site WordPress a précédemment subi une compromission.
Ne pas activer de clé privée de production avant d'avoir terminé et validé
le nettoyage du site, des extensions et des comptes. Un attaquant ayant
repris le contrôle PHP du site pourrait utiliser la clé même hors du webroot.
Une séparation du service de signature / HSM est préférable en production.

Si la clé est absente ou non accessible, la réponse est **HTTP 503**.
Il n'existe **aucun mode de licence non signée en secours**.

Le fichier `license-signing-public.pem` est destiné au client. L'intégrer
de façon **épinglée** à la distribution OGIPEN, avec l'émetteur attendu et
les identifiants de logiciel/majeure. Ne jamais remplacer cette clé en
téléchargeant un fichier public non authentifié à chaque démarrage.

## Format de signature et validité

- ECDSA sur `prime256v1` / P-256, SHA-256.
- Trois segments encodés en base64url sans `=`.
- Header `typ=SLMMS-ENT, alg=ES256-DER, v=1, kid=<id>`.
- Le troisième segment contient une signature **ECDSA DER** :
  contrairement à JWS `ES256`, ce format n'utilise **pas R||S brut**.
- Corps : `iss, aud, lic, sub, uid, software_id, software, major, modules,
  iat, nbf, exp`.
- Pas de mot de passe, d'adresse e-mail ou de secret SQL dans le jeton.
- Durée maximale **3600 secondes** et jamais au-delà de la date d'expiration
  de la licence. Les modifications/revocations prennent effet pour
  l'émission de nouveaux jetons, mais **un jeton déjà signé peut rester
  valable jusqu'à une heure**.

Cette durée **n'est pas encore le mode hors ligne**. Il faudra concevoir
la révocation/validité hors ligne et les liaisons appareil/sessions
distinctement avant de rendre l'authentification opérationnelle.

## Consommateur OGIPEN .NET 10

Le code `src/OGIPEN.Core/Security/SignedSoftwareEntitlement.cs` du dépôt
`aguamemnon/ogipen`, branche `modernisation-csharp`, vérifie :
- signature ECDSA DER à l'aide d'une clé publique épinglée ;
- émetteur HTTPS attendu, audience, logiciel et version ;
- modules requis, identifiants du bénéficiaire, horodatages et durée <=1h ;
- refus des signatures altérées, clés différentes ou données expirées.

Ce vérificateur est **une brique portable .NET 10**, utilisable aussi dans
un processus Linux. **Il n'est pas encore connecté au formulaire Login,
à la sélection de projet ni à la vérification de droits métiers**.

## Cache et isolation des données

Dans le VirtualHost HTTPS Apache qui héberge WordPress, exclure les chemins
WooCommerce privés et **`/wp-json/` du cache Apache**, ainsi que dans
W3 Total Cache et tout reverse proxy éventuel.

Un `CacheQuickHandler` Apache peut renvoyer une réponse avant que PHP
exécute `nocache_headers()` ; **les en-têtes PHP seuls ne suffisent pas**.
Les API de licences doivent obligatoirement être testées sous deux comptes
utilisateurs distincts (aucune réponse personnalisée ne doit être partagée).

## Recette fonctionnelle avant production

1. S'assurer que la version PHP-FPM du VirtualHost et sa base de données
   correspondent bien au site WordPress sain.
2. Enregistrer un logiciel Progelec et des modules publiés.
3. Créer une licence v1 avec places et échéance.
4. Ajouter deux bénéficiaires et vérifier un compte de portail par e-mail.
5. Émettre le jeton v1 via un compte individuel en HTTPS et vérifier en C#.
6. Vérifier les refus : autre compte, v2 non achetée, module non inclus, SAM,
   AD, blocage, suspension, expiration, quota dépassé, clé privée absente.
7. Renouveler la v1 puis effectuer un upgrade payé v1 → v2 ; vérifier
   qu'un jeton v1 n'est plus émis et qu'un jeton v2 est possible.
8. Contrôler l'absence de fuite via le cache et tester la fenêtre de
   révocation des jetons déjà émis.
