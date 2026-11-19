<?php

/**
 * Provider fields inside Event Tickets' "Get tickets" form, for Tickets Sharp.
 *
 * Event Tickets includes `v2/tickets/commerce/fields/{orm_provider}` here and
 * then adds a hidden `provider` input itself. Tickets Sharp needs nothing more:
 * the form's script posts the quantities as JSON, and
 * TicketsSharp\Commerce\CartHandler reads them from there.
 *
 * `tsharp_basket` is set to 1 by assets/js/basket.js once it has filled the
 * form from the basket; the cart handler then sets the basket to the posted
 * quantities rather than adding them.
 *
 * @package TicketsSharp
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<input type="hidden" name="tsharp_form" value="1" />
<input type="hidden" name="tsharp_basket" value="" data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" />
