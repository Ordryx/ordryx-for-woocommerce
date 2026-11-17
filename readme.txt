=== Ordryx for WooCommerce ===
Contributors: ordryx
Tags: inventory, manufacturing, orders, stock, production
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your store to Ordryx: its orders flow in to plan, make and ship them, and orders shipped in Ordryx are marked shipped here.

== Description ==

Ordryx is inventory and production for small makers: what's on the shelf, what to make next, and what to ship. This plugin connects a WooCommerce store to the shop's own Ordryx with one code. Your Ordryx is your shop's own: it runs on a computer of yours, or on Ordryx's servers when Ordryx hosts it for you.

* **One code, then WooCommerce's own Allow screen.** Get a code in Ordryx (Sales › Sales channels › WooCommerce), type it under WooCommerce › Settings › Ordryx, tick that you agree to Ordryx's terms of service and privacy policy, check both names, press Connect and allow a **read-only** key.
* **Orders come in by themselves.** Once your Ordryx has run the store's first sync (Sync now on the store's page in Ordryx, once its starting stock is set) and "Sync orders automatically" is on there (Settings › WooCommerce), it reads the store as often as you chose, and usually within a couple of minutes of an order changing: the plugin tells Ordryx's server that something changed, a notice that carries no order details at all. Until then, orders come in when someone presses Sync now (the first time, in Ordryx).
* **Payment processors' fees, and paid.** Ordryx's key sees two extra fields on each order: the fee WooPayments or Stripe charged, so Ordryx can show what each sale really made, and whether WooCommerce counts the order paid (a status another plugin adds to WooCommerce's paid ones, like "Packing", counts too).
* **Shipped from Ordryx.** An order you ship in Ordryx is marked Completed here, with its tracking (in WooCommerce's Fulfillments, Shipment Tracking or Advanced Shipment Tracking if you use one, else a note to the customer) and a private note saying who shipped it. Only a tracking number (letters and digits, its spaces taken out: at most 6 letters, or one with a dash, which goes only between digits), a carrier's plain name and a link to that carrier's own tracking page reach the customer. WooCommerce then does what it does for any order marked Completed: it emails the customer (and for the note, when tracking goes there), and does more for an order not yet paid (see the FAQ). This plugin writes it, never Ordryx's key, which stays read-only.
* **Pause and disconnect.** Deactivating the plugin pauses Ordryx; deleting it disconnects and deletes Ordryx's key from your store (unless you renamed that key or gave it Read/Write in WooCommerce: see the FAQ). Deactivating it for a multisite network pauses each connected site's store where the plugin no longer runs, as many as 30 seconds allow: one past that keeps syncing as it was, without the plugin's notices, until you pause it in Ordryx.

Administrators connect, pause, switch sales channels and disconnect. Shop Managers see what flows and may press Sync now.

== External services ==

This plugin connects to **Ordryx's server, account.ordryx.com**, run by Ordryx LLC.

* **When you connect:** the "check both sides" screen shows your Ordryx's key (a fingerprint like 7F3A-91C2-4D0B-E8A5) to match with the one on your Ordryx's WooCommerce page; only orders signed with that key are ever marked shipped here.
* **When you type a code:** the code, your site's address and name, and its WordPress, WooCommerce and plugin versions, whether it stores orders in WooCommerce's own tables, the name of the Administrator connecting, and that they ticked the box agreeing to Ordryx's terms of service and privacy policy (nothing is sent until they do). Ordryx's server keeps that agreement, with the terms' version, when and who, once the store is connected. The server checks the code with the Ordryx that made it and answers with that Ordryx's name and a secret only this site and the server know.
* **When WooCommerce's key arrives:** Ordryx's server asks this site once, at `/?rest_route=/ordryx/v1/proof`, for proof that the pairing came from this plugin (a code signed with the pairing's secret, which only this site holds). Nothing else is asked or read there.
* **When an order changes:** a signed notice that something changed, at most once a minute. It names no order and holds no order details. While the store is paused, it first asks your Ordryx whether it was resumed (the same ask the tab makes, naming no one), at most every 15 minutes.
* **When you open the Ordryx tab, and once a day:** a signed check of where the connection stands. While connected, opening the tab also asks your Ordryx what flows (at most every 30 seconds); the answer comes back through the same server, which keeps none of it.
* **When an order is shipped from your Ordryx:** Ordryx's server sends this site's `/?rest_route=/ordryx/v1/shipped` the order's id, its carrier, tracking number and link, and who shipped it, as your Ordryx signed it (the plugin checks that signature against your Ordryx's own key, given at pairing, so Ordryx's server alone can't mark an order shipped). The server passes it on and keeps none of it.
* **When WooCommerce erases a customer's personal data from orders** (Tools › Erase Personal Data with "Remove personal data from orders on request" on, or its retention settings): the ids of those orders, so your Ordryx forgets their customer's name, email and ship-to, and what they typed for a line, at once. Nothing else is sent, and Ordryx's server keeps none of it.
* **When you press Sync now, Pause, Resume, Disconnect or a sales channel:** a signed ask for your Ordryx, with the name of the person who pressed it and whether they're an Administrator.
* **When you deactivate or delete the plugin:** a signed notice of that, with the name of the person who did it. Deleting it first sends, once more, the ids of orders erased here that your Ordryx hasn't taken yet.
* **When you cancel connecting:** a signed notice of that, naming no one.
* **With every signed call (each notice, check and ask):** this site's Site Address (Settings › General), over https, so Ordryx's server can refuse one sent from a copy of the site.

WooCommerce's Allow screen sends the read-only key to the same server, which passes it straight on to your Ordryx and doesn't keep it. Your Ordryx then reads your orders and products directly from your store.

Terms: https://ordryx.com/terms · Privacy: https://ordryx.com/privacy

== Privacy ==

What your Ordryx reads with the key, and keeps:

* Orders: their number, status, dates and where each was placed (checkout, typed in, an app), with their lines, prices, taxes, shipping, fees and refunds. Products: their names, SKUs, prices, stock and variations. The store's name, its order statuses and WooCommerce's version.
* Who bought each order: the billing name and email only, kept with the order until the store is disconnected (see below), and forgotten at once when WooCommerce erases them from it.
* What the customer typed or chose for each line, as WooCommerce's own order screen shows it under the line: a product add-on's field (the text to engrave, say) and a variation's options, never the hidden details WooCommerce and other plugins keep there. Kept with the order's lines, for making and packing it, and forgotten with the customer's name and email.
* For each order waiting to ship, the ship-to name, company, address and phone, for its label: your Ordryx gives them to the carrier or label service you buy the label from. Cleared 90 days after the order is done, or after 90 days with nothing changed on one still open (your Ordryx asks the store for it again, so one the store still has comes back), or at once when WooCommerce erases the customer's personal data from the order.
* Never the billing address or billing phone, and never customer accounts: the key could read them, but your Ordryx never asks for them.

Your Ordryx uses them to plan what to make, take stock when orders ship, buy labels and report your sales. An erasure made while the store isn't connected is sent once it's connected again. If the store is disconnected (Disconnect here or in Ordryx, deleting the plugin, or connecting the store to another Ordryx), your Ordryx keeps the orders it has read, as your shop's sales, but their customer details go: the ship-to, the customer's name and email and what they typed for a line, at once for orders still waiting to ship, 29 days later for the rest, unless the store is connected to it again first. Your Ordryx's backups and off-site copies made before then keep them until they're pruned (14 backups and 30 off-site copies are kept, unless you chose other numbers).

What Ordryx's server keeps: no order data and no key. For each connected store, its address and name, which Ordryx it's connected to, who connected it and when, that they agreed to Ordryx's terms (the version, when and who), the number WooCommerce gave Ordryx's key (never the key itself), and the store's secret for signing the plugin's notices, encrypted. Deactivating or deleting the plugin is kept, with who did it, until your Ordryx has heard of it, and a month more. A store's record goes a month after it's disconnected. When its Ordryx is removed from its Ordryx account, the record goes a month after the plugin's next check (which hears so and deletes Ordryx's key), or a year after the removal if the plugin never checks. A connection never finished goes a day after it ran out. The agreement to Ordryx's terms stays after the record goes, as the version and when (so the store is asked again only when the terms change), but who agreed is kept only as long as the store's record, or until the Ordryx account it was connected through is deleted.

What the plugin keeps in WordPress:

* The connection: the store's id on Ordryx's server and its secret; your Ordryx's name, address and key, and the code you typed and who made it there; the address this site was connected at and what WordPress called it then; who connected it and when; and the ids of the keys WooCommerce made for Ordryx, with the last 7 characters WooCommerce shows of the one in use (never the key itself), so another key given its id later isn't taken for it.
* The last 200 shipments from Ordryx, and any still waiting for WooCommerce to change their status: each one's id and what the plugin answered, so a shipment sent twice is marked once.
* The ids of orders WooCommerce erased, until your Ordryx has them. Deleting the plugin sends them once more; whatever your Ordryx can't take then is lost (the Plugins screen says beforehand when some are waiting). 20,000 at most: past that, a later erasure isn't passed on (the Plugins screen says so), which can only happen on a store disconnected for a long time.
* What flows, as your Ordryx last said, for 30 seconds.

Deleting the plugin deletes all of it, once those erasures are sent, and Ordryx's key in WooCommerce: on a multisite network, on every site of it.

== Frequently Asked Questions ==

= Can Ordryx change my store? =

Only through this plugin, and only in two ways: it marks an order shipped from Ordryx Completed, and it deletes Ordryx's key once the store is disconnected. Ordryx's key itself is read-only (WooCommerce's Allow screen says "Read" access), so nothing in the store can be changed with it.

When you ship an order in Ordryx, this plugin adds its tracking (if it has one) and marks the order Completed, with a private note saying who shipped it. It does so only for a message signed by your Ordryx's own key (the one you matched when connecting), only for an open order whose lines are still the ones shipped, only while Ordryx's key is still in the store, and only once for each shipment. A tracking number reaches the customer only if it's letters and digits, with its spaces taken out (40 at most, a digit among them and at most 6 letters, a dash only between two digits and only with one letter at most), and the carrier's name only if it's a plain name: anything else is left out, and the private note says which. If WooCommerce can't change the order's status (its own note on the order says why), the tracking stays and only the status is tried again, never a second note to the customer.

The plugin changes nothing else itself. WooCommerce then does what it does for any order marked Completed: it emails the customer. For an order not yet paid, it also takes the order's stock if it hadn't yet, counts the sale and any coupon, marks the customer a paying customer and gives them the order's downloads. Your Ordryx ships an order not yet paid only while "Ship orders before they're paid" is on in its Sales settings.

When you disconnect the store in Ordryx, or your Ordryx is removed from its Ordryx account, this plugin deletes Ordryx's key from WooCommerce at its next check: when an Administrator opens the Ordryx tab, and once a day. If the plugin was switched off for longer than Ordryx's server keeps the store's record (see Privacy), the Ordryx tab says the server doesn't know the store any more and offers to delete the key. A key you renamed or gave Read/Write in WooCommerce isn't deleted, as you may use it for something else now: the tab says so, and you can revoke it under WooCommerce › Settings › Advanced › REST API (deleting the plugin leaves it there too, so revoke it first).

= My host blocks outgoing connections =

The plugin needs to reach account.ordryx.com over HTTPS, and Ordryx's server needs to reach this site's `/?rest_route=/ordryx/v1/proof` once while connecting, and `/?rest_route=/ordryx/v1/shipped` for orders shipped from Ordryx. WooCommerce's Allow screen doesn't work with Plain permalinks: pick any other setting under Settings › Permalinks before connecting. If the Ordryx tab says the server can't be reached, allow it in your host's or security plugin's settings. Meanwhile your Ordryx still reads your orders, on its own timer once the store's first sync has run and "Sync orders automatically" is on, or when someone presses Sync now in Ordryx.

= Does it need HTTPS? =

Yes: WooCommerce only gives out a key over HTTPS.

= Where is the connection's secret kept? =

In this site's database, encrypted with a key made from wp-config.php's AUTH_KEY and SECURE_AUTH_KEY, which every WordPress installer writes. Changing those keys (some security plugins do, after a scare) makes the secret unreadable: the Ordryx tab then asks for a new code from Ordryx, and the store carries on from where it stopped. To keep the connection through such a change, define your own key in wp-config.php, at least 32 random characters, before connecting: `define( 'ORDRYX_SECRET_KEY', '…' );`.

= I made a copy of my site (a staging site, a host's one-click copy) =

The copy holds the live store's connection too, so the plugin keeps the address it was connected at, and whether WordPress said it was a live site, and this plugin sends nothing from a copy to your Ordryx: order changes, erasures, switching the plugin off or deleting it on the copy leave the live store and your Ordryx as they are, and an order shipped from Ordryx marks nothing on the copy. The only thing a copy at another address asks is a check of where it stands, when an Administrator opens its Ordryx tab or presses Forget the connection here, and Ordryx's server refuses it, knowing the store's address. A copy of a store connected before 0.5.1, made before the store's first check on 0.5.1, doesn't know the store's address yet: its first call, whatever it is, goes to Ordryx's server, which refuses it and tells it the store's address. From then on it asks only that check. (One that WordPress calls staging, development or local sends nothing at all, even then.) A copy at the store's own address that WordPress calls staging (its environment type) asks nothing at all. That's what this plugin does on a copy. The store's secret is kept encrypted with a key from wp-config.php, so a copy of the database alone, or a backup of it, can't use it. A copy of the whole site, wp-config.php with it, holds the secret too, and with it could ask what an Administrator can from the Ordryx tab, though never read an order or mark one shipped. Keep copies and backups as safe as the live site.

The copy's Ordryx tab says it looks like a copy. There's nothing to do. If you'll push the copy back over the live site, leave the connection as it is. To connect a copy at another address to an Ordryx of its own instead, press Forget the connection here, which clears the connection on the copy only, then type a code from that Ordryx. A copy at the store's own address can't connect to an Ordryx of its own until it has an address of its own. There, Forget the connection here only stops it holding the live store's connection: worth doing before anyone sets its environment type back to production.

A copy that keeps the live store's own address, and that WordPress still calls a live site (its environment type), can't be told apart: one on your computer reached through a hosts-file entry, say. Clear its connection first with WP-CLI, `wp option delete ordryx_state ordryx_secret`. If the store itself moved to a new address, its Ordryx tab says what to do.

== Changelog ==

= 0.5.10 =
* The connection's secret is kept encrypted, with a key from this site's wp-config.php (its AUTH_KEY and SECURE_AUTH_KEY, or ORDRYX_SECRET_KEY if you define one), so a copy of the database alone can't sign for the store. A secret kept by an earlier version is encrypted the first time it's used. A site whose wp-config.php has no keys of its own can't connect until it has them. If those keys change, the tab asks for a new code from Ordryx; the store carries on from where it stopped.

= 0.5.9 =
* A code that's wrong, has run out or was already used is said as such, and the tab says where to get a new one in Ordryx (Sales › Sales channels › WooCommerce).

= 0.5.8 =
* The readme, the Ordryx tab and What flows now say that Ordryx keeps what a customer typed or chose for an order line (an engraving's text, say), and that it goes with their name and email: at an erasure and when the store is disconnected. Ordryx has kept it since 0.0.253; nothing else changed.

= 0.5.7 =
* The plugin's source is public, release by release, at https://github.com/Ordryx/ordryx-for-woocommerce (issues welcome; security problems to security@ordryx.com).
* An order shipped from Ordryx is only ever marked Completed, whatever the message says, as this readme always said. A carrier's name with a phone number or a sentence in it, or a link to anything but that carrier's tracking page for that number, never reaches the customer.
* A request that dies while marking an order shipped (a hook's fatal error, a time limit) adds no second tracking note when it's tried again. A status another plugin moves the order on to from Completed counts as done, so Try again sends no second email.
* A key you renamed or gave Read/Write in WooCommerce isn't deleted on disconnect, as before, but the tab now says it's still there and can still read, rather than that there was no key.
* Cancel says when the store was already connected, instead of "Nothing was connected". The screen before Continue says what Continue sends, and the screen before Connect says which orders Ordryx reads: those still open at the store's first sync and every new one. The tab calls Ordryx's page Sales channels, as Ordryx does.
* The Plugins screen says when 20,000 erasures are waiting, the most the plugin keeps.
* A daily check left over after a cancelled or refused connection goes, and "Late" ends as soon as Ordryx's server answers a check. Each visit to the store no longer makes the plugin ask about its jobs. On a large network, deleting the plugin keeps to its 30 seconds.
* Until the plugin is on WordPress.org, WordPress won't offer an update for it from there (Update URI).

= 0.5.6 =
* A store that moved to a new address (a new domain, or out of a host's temporary one) can connect again there and carry on: its Ordryx tab says how. In Ordryx, The store moved… gives a code for the new address; forget the connection on the moved site and type it. Ordryx checks the store's newest orders are there as they were before it moves anything. Needs Ordryx 0.0.242 or later.
* When your Ordryx turns a code down itself (another store's orders are there, say), the tab says "Your Ordryx didn't accept that" and to check in Ordryx, as 0.5.5 meant to: it said Ordryx's server couldn't do it.

= 0.5.5 =
* When your Ordryx turns down a connection (it's paused, another store is connected there, or it couldn't use WooCommerce's key), this tab now says "Your Ordryx didn't accept that" and to check in Ordryx, instead of blaming a firewall. Ordryx's own Stores page says why. When your Ordryx never answered (it was off or offline), the tab says it didn't answer in time and to check it's running and online, then type a new code.

= 0.5.4 =
* Connecting asks you to agree to Ordryx's terms of service (https://ordryx.com/terms) and privacy policy (https://ordryx.com/privacy): a tick box under the code, and Continue says to tick it until you do. Ordryx's server keeps the agreement once the store is connected. Move it here sends the agreement given with its code only for the Administrator who ticked it; anyone else, or a code typed before 0.5.4, ticks the box on the Move form.

= 0.5.3 =
* Paid as WooCommerce counts it: a status another plugin adds to WooCommerce's paid ones ("Packing", "Awaiting shipment") is paid in Ordryx too, so its orders can be shipped from there.
* Resumed in Ordryx while the Ordryx tab still said Paused, orders shipped from Ordryx are marked shipped here at once (they were refused for up to a day). While paused, an order change asks your Ordryx whether it was resumed, at most every 15 minutes.
* Erasures made while one was being sent, or by two erasers at once, are all kept and sent; before, some were deleted unsent.
* With Ordryx's key deleted in WooCommerce, orders shipped from Ordryx aren't marked shipped here until the store is connected again (then they are), as the tab says. A key renamed or given Read/Write is still Ordryx's: it still reads, and gets the fees and paid fields. So is one given to another person, which Disconnect still deletes. One set to Write can't read (WooCommerce refuses it), and the tab says to set it back to Read. Another key that later gets the deleted key's id (some databases reuse ids after a restart) isn't Ordryx's.
* Only a tracking number (letters and digits, its spaces taken out: 40 at most, a digit among them and at most 6 letters, a dash only between two digits and only with one letter at most), a carrier's plain name and a link to that carrier's own tracking page (USPS, UPS, FedEx, DHL) reach the customer: anything else, words sent with it too, is left out, and the private note says which.
* Advanced Shipment Tracking gets the date shipped as it takes it (the store's day) and DHL as "DHL Express", its own name for it; if it keeps nothing, the customer gets the tracking note instead.
* When WooCommerce can't change an order's status (judged by the order as WooCommerce has it saved, as WooCommerce answers that it changed even when its save failed), Ordryx is told so, with the tracking already added: trying again changes only the status, however many orders ship meanwhile.
* Deleting the plugin first sends erasures still waiting once more, and the Plugins screen says beforehand when some are waiting. Deactivating it for a multisite network pauses each connected site's store where the plugin no longer runs, as many as 30 seconds allow.
* The tab says what your Ordryx does with order changes: whether Sync orders automatically is on there, whether the store's first sync has run (and both, for a new store), whether it's paused, and how often it syncs meanwhile, never a promise of "within a minute". While Ordryx's key is deleted or set to Write, it says order notices are held until the key is back.
* Clearer sentences when your Ordryx is offline, slow or busy, instead of the firewall one, and the firewall sentence names both routes Ordryx's server reaches.
* A notice sent while the store was disconnected in another tab can no longer bring the connection back.
* For development, the account's address (ORDRYX_ACCOUNT_URL) is taken over https only: WooCommerce posts its key only to an https address.
* The readme, the Ordryx tab and What flows say what your Ordryx keeps once the store is disconnected (Ordryx 0.0.210): the orders, as your shop's sales, but their ship-to and customer's name and email go, at once for orders still waiting to ship, 29 days later for the rest; that an open order with nothing changed for 90 days loses its ship-to; and that backups and off-site copies keep what they hold until they're pruned. Before, the readme said your Ordryx kept the orders as they were.
* The Connect card says what this plugin changes in your store, as this readme does: an order you ship in Ordryx is marked Completed here, with its tracking and a private note, and WooCommerce emails the customer; once the store is disconnected, Ordryx's key is deleted. Before, it said only that Ordryx can't change anything with its read-only key.

= 0.5.2 =
* Tested with WordPress 7.1.
* The readme says what the plugin writes in your store: an order shipped from Ordryx is marked Completed with its tracking, and WooCommerce emails the customer (and, for an order not yet paid, takes its stock and gives its downloads). It also says what Ordryx reads and keeps, and for how long, what Ordryx's server and the plugin keep, and that the ship-to phone is kept with the ship-to address, for the label. The Ordryx tab says so about the phone too.
* Deleting the plugin on a multisite network clears it from every site, not only the main one: each site's connection, Ordryx's key in its WooCommerce, its notice to Ordryx, and its scheduled checks, even where WooCommerce isn't active on the main site.
* When Ordryx's server no longer knows the store (it let the store's record go while the plugin was switched off, say), the Ordryx tab offers Administrators to delete Ordryx's key, and no longer says nothing flows: an Ordryx removed from its account may still hold the key and read orders.
* Ordryx's key is read from the request as WooCommerce reads it, cleaned the same way.
* The release zip leaves out the plugin's own .gitignore, and any .DS_Store.

= 0.5.1 =
* A copy of the site (a staging site, a host's one-click copy) no longer acts for the live store: the plugin keeps the address it was connected at, and whether WordPress said it was a live site. From any other address it sends Ordryx nothing but a check of where it stands, when an Administrator opens the Ordryx tab there or presses Forget the connection here, and Ordryx's server refuses it. (A copy of a store connected before this version, made before the store's first check on it, doesn't know the store's address yet: its first call, whatever it is, goes to Ordryx's server, which refuses it and tells it the store's address. From then on it asks only that check. One that WordPress calls staging, development or local sends nothing at all, even then.) From a live site WordPress now calls staging, it sends nothing at all. The copy's Ordryx tab says so and offers Forget the connection here; the Plugins screen says so too.
* Every signed call names this site's address, and Ordryx's server refuses one from another address, so a store connected before this version is covered too: it learns its address from Ordryx's server at its next check. It's taken to have been a live site (WordPress's default). If WordPress doesn't call it production, its Ordryx tab says it looks like a copy: set its environment type to production, or press Forget the connection here and connect it again with a code from its Ordryx.
* Pairing names the site by its Site Address setting (Settings › General).
* An order shipped from Ordryx is taken only by the connected store at its own Site Address, with or without "www.": a copy of the site takes none, even one sent on to it, so it never marks an order shipped or emails a customer. A store whose address moved takes none either; its Ordryx tab says what to do.

= 0.5.0 =
* When WooCommerce erases a customer's personal data from orders, your Ordryx forgets their name, email and ship-to on those orders at once.

= 0.4.0 =
* Orders shipped from Ordryx are marked shipped here, with their tracking where the store keeps it, checked against your Ordryx's own signature. Connecting shows your Ordryx's key to match with the one Ordryx shows.
* The dashboard's "Shipped and tracking" row says where tracking goes on this store.
* A store connected before this version connects once more (Disconnect, then a new code) to take orders shipped from Ordryx.

= 0.3.0 =
* The Ordryx tab shows what flows once connected: each sales channel (switched by Administrators), which statuses mean shipped, the last sync and recent activity, from your Ordryx.
* Pause, Resume and Sync now on the tab. Shop Managers see everything and may press Sync now.

= 0.2.0 =
* Ordryx's server checks a pairing came from this site's own plugin (a small public proof route) before a key goes anywhere.
* "Already connected elsewhere" no longer names the other Ordryx.
* A Cancel that comes after the key was taken keeps the connection.
* Says plainly when Ordryx's server couldn't reach this site to confirm.

= 0.1.0 =
* Connect with a code from Ordryx, check both sides, allow a read-only key.
* Order notices (no order details), the payment processor's fee for Ordryx's key, the daily check.
* Deactivating pauses Ordryx; deleting the plugin deletes Ordryx's key and disconnects.
