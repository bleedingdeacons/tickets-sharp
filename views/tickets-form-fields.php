<?php

/**
 * Provider fields inside Event Tickets' "Get tickets" form, for Tickets Sharp.
 *
 * Event Tickets includes `v2/tickets/commerce/fields/{orm_provider}` here and
 * then adds a hidden `provider` input itself. Tickets Sharp needs nothing more:
 * the form's script posts the quantities as JSON, and
 * TicketsSharp\Commerce\CartHandler reads them from there.
 *
 * @package TicketsSharp
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<input type="hidden" name="tsharp_form" value="1" />
