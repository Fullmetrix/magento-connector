# Changelog

## 1.7.0

- Le script de suivi est servi depuis la boutique : toutes les 10 minutes, la tâche planifiée `fullmetrix_sync_tracker_script` (groupe `fullmetrix`) télécharge le script choisi par Fullmetrix en 3 s au plus, vérifie son empreinte et son origine, puis l'écrit dans `pub/media/fullmetrix/fm-<version>.js` par un fichier temporaire renommé. La balise pointe alors vers ce fichier, avec `data-platform="magento"` et `data-fm-origin`. Les envois partent toujours vers Fullmetrix.
- Sans fichier local valide, dossier non inscriptible ou après l'interrupteur à distance de Fullmetrix, la balise reste l'adresse distante d'avant, à l'identique. Si le fichier local ne se charge pas, la page recharge l'adresse distante.
- Après un échec, la tâche attend 15 minutes, puis de plus en plus longtemps jusqu'à 6 heures, et reprend seule au premier succès.
- Une version remplacée n'est supprimée qu'après 7 jours et quand le cache de page ne peut plus la servir. La version courante et la précédente restent toujours.
- La politique de sécurité du checkout de Magento 2.4.7 et plus autorise désormais `fullmetrix.com` et `app.fullmetrix.com` en `script-src` et `connect-src` : le suivi n'est plus bloqué au paiement.
- La page de succès expose la commande au script (`data-fm-order-id`, numéro, montant et devise), lue dans la session déjà chargée par Magento, sans aucun appel réseau.
- Le cookie visiteur `fm_vid` est reposé par le serveur de la boutique pour tenir sur Safari : `POST /fullmetrix/keep` répond 204 dès le lancement de Magento, avant l'aire, le contrôleur frontal et la session, sans aucun autre cookie, et réécrit `fm_vid` avec la même valeur (`Path=/`, `SameSite=Lax`, `Secure` en HTTPS, sans `Domain`). La valeur est lue dans le cookie, doit être un UUID v4 égal au corps, unique, et `Sec-Fetch-Site` doit être absent ou `same-origin`. Toute autre requête passe sans changement.
- La balise porte `data-fm-keeper` quand Fullmetrix active ce point (actif par défaut). Fullmetrix peut le couper, régler la durée du cookie (1 à 390 jours, 390 par défaut) et ajouter des attributs de consentement sur la balise (`type`, `data-cookieconsent`, `data-category`, `data-cookiescript`, `data-cmp-ab`). Point coupé : réponse 404 sans cookie.
- La désinstallation supprime `pub/media/fullmetrix/`. Après la mise à jour, lancer `setup:upgrade`, `setup:di:compile`, `setup:static-content:deploy` (avec les langues de la boutique) puis `cache:flush`. En mode production, `setup:upgrade` vide `pub/static` : sans `setup:static-content:deploy`, la boutique répond 500.

## 1.6.1

- Le statut de stock d'un produit configurable vient du stock affecté au site, comme la vente dans Magento. Il ne retombe plus sur l'ancien indicateur du produit parent quand sa quantité vendable n'existe pas.

## 1.6.0

- Les appels de Fullmetrix sont signés en v2 : méthode, query exacte, horodatage, nonce, empreinte du corps et opération visée (`fm_op`). Un paramètre pris dans le chemin au lieu de la query signée est refusé. Une commande ne peut pas être rejouée : son nonce est gardé parmi les 64 derniers.
- L'ancienne signature v1 est refusée. Elle ne se réactive qu'en secours, depuis le serveur, par `bin/magento config:set fullmetrix/security/signature_v1 1`.
- Les réponses JSON à Fullmetrix sont signées.
- Une requête d'export qui échoue trois fois de suite arrête le flux par une ligne `fatal`, sans marqueur de fin. Un échec passager reprend après la dernière ligne envoyée.
- La liste des identifiants mis à jour est triée par identifiant : une commande modifiée pendant le parcours n'est plus sautée.
- Les tables tierces liées sont lues par jointure, filtrées par identifiant et dans un ordre stable. Leur liste de colonnes n'est plus tronquée.
- Les commandes `coupon.update` et `coupon.delete` disparaissent. `coupon.create` refuse un code invalide, un montant négatif ou non numérique et une limite d'usage inférieure à 1.
- Après la mise à jour, lancer `setup:upgrade`, `setup:di:compile` et `cache:flush`.

## 1.5.1

- Same code as 1.5.0, republished under a new version number.

## 1.5.0

- The module no longer works during shoppers' requests. Checkout, cart, login, imports and stock updates only record the identifier of what changed, and the Magento cron sends everything to Fullmetrix every minute.
- Queue rows written during a database transaction are written after the commit, never inside it, so a Fullmetrix row can neither slow down nor break a checkout, a save or an import.
- No module error can interrupt an order or a save, and storefront pages never contact Fullmetrix.
- The cron job runs in its own `fullmetrix` group, in a separate process, and never delays the other Magento jobs. A crontab that only runs explicit groups must add `bin/magento cron:run --group=fullmetrix`.
- A refused row no longer blocks the others, tracking is sent even while Fullmetrix refuses store data, and catalog changes always get a share of each run.
- Cart images sent with tracking events are the storefront thumbnails again.
- Once an hour, the queue purges tracking unchanged for 24 hours, rows unchanged for 7 days and rows Fullmetrix refused 30 times, in small batches that never hold up a storefront write. Consents and deletions are never purged for their refusals, only after 7 days without change. A network outage or an unreachable Fullmetrix API (timeout, HTTP 429, 502, 503, 504, Cloudflare 520 to 527 and 530) only delays rows by a minute and never counts toward the 30 refusals. An HTTP 500 counts as a refusal of that row alone and never holds up the rows behind it.

### Upgrading and rolling back

- In production mode, run `setup:upgrade`, `setup:di:compile` and `cache:flush` for the upgrade, and the same sequence for a rollback. Without `setup:di:compile`, the checkout fails right after payment.
- Before rolling back to 1.4, empty the queue: wait until `bin/magento fullmetrix:status` shows `Queue: 0 pending`. 1.4 cannot read the rows 1.5 writes and would drop them.
- PHP 8.1 or later is required. Do not copy the module onto a store still running PHP 7.4.
