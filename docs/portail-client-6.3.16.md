# SLM Plus Multi-Software 6.3.16 — Portail licences clients

Cette version améliore l'interface **Mon compte → Mes logiciels et licences**.
Elle ne modifie **ni les tables historiques de SLM Plus ni les contrats émis**.

## Tableau de bord client

L'utilisateur voit uniquement les licences dont il est titulaire, ou les licences
dont il est gestionnaire délégué (rôles lecteur / gestionnaire utilisateurs).
Les contrôles d'accès sont toujours appliqués au serveur, jamais par le CSS.

**Vue globale :**
- nombre de licences logicielles ;
- places attribuées, places disponibles ;
- licences nécessitant une attention ;
- fiche par logiciel avec version majeure, modules autorisés, date d'expiration,
  statut et nom du titulaire.

**Sur une licence :**
- Informations : statut, version, modules, sièges et expiration ;
- Utilisateurs : consultation, ajout, suspension, suppression des bénéficiaires
  si le compte a `manage_users` ;
- Gestionnaires : délégation et révocation uniquement pour le titulaire du
  portefeuille ou l'administrateur (permission `manage_managers`).

Un lien de détail n'autorise jamais à consulter une autre licence en modifiant
son identifiant dans l'URL. Les clés de licence ne figurent pas sur les cartes
du portefeuille, mais restent visibles dans la fiche accessible aux seuls
comptes habilités.

## Alertes visuelles de validité

Le statut contractuel bloqué/suspendu a priorité.
Si la licence est active :
- plus de 30 jours : **Active** ;
- de 30 à 16 jours : **Échéance proche** ;
- de 15 à 1 jour : **Échéance imminente** ;
- date atteinte ou dépassée : **Expirée** (nombre de jours depuis expiration).

Une alerte ne confère **aucun jour de grâce** et n'autorise pas la connexion
d'une licence expirée. La validation serveur des droits logiciels et les
licences signées ne sont pas encore raccordées.

## Mise en cache et informations personnelles

Le fork émet des entêtes `Cache-Control: private, no-store` pour
**Mon compte**, **Panier** et **Paiement** via WooCommerce, et utilise
`DONOTCACHEPAGE` pour les caches WordPress.

**IMPORTANT : sur le serveur Apache trueshield.fr, un cache global
`<Location /> CacheEnable disk` a été observé.** Une entrée déjà servie par
Apache CacheQuickHandler n'exécute pas WordPress : les entêtes PHP seuls
ne protègent pas ce cas. Après sauvegarde de la configuration, ajouter dans
le VirtualHost HTTPS concerné (en dehors de `<Location />`) des exclusions
correspondant AUX VRAIS chemins du site :

```apache
CacheDisable "/mon-compte/"
CacheDisable "/my-account/"
CacheDisable "/panier/"
CacheDisable "/cart/"
CacheDisable "/commande/"
CacheDisable "/checkout/"
CacheDisable "/wp-json/"
```

Adapter les chemins à la configuration WooCommerce, faire
`apache2ctl configtest` puis `systemctl reload apache2`.
Si un autre proxy ou CDN est présent, y appliquer également l'exclusion.
Exclure aussi les pages dynamiques dans W3 Total Cache.

Un test avec deux comptes distincts est **obligatoire** : le navigateur
de l'utilisateur B ne doit jamais recevoir un HTML ou JSON de l'utilisateur A.
Le test doit être répété après connexion/déconnexion et avec cache activé.

## Tests automatisés

`tests/multisoft/client-dashboard.php` vérifie :
- échéances à 30 et 15 jours ;
- expiration, blocage et suspension ;
- version majeure, choix d'onglet limité, URLs ;
- chargement des styles uniquement sur les pages Mon compte.

La CI exécute les tests sur PHP 7.4, 8.2, 8.3. **Aucun test end-to-end
WordPress/WooCommerce/Apache n'est inclus.** Ne pas déployer ce build en
production sans recette fonctionnelle et contrôle des caches privés.
