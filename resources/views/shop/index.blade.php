<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $shopName }} · Shop</title>
    <link rel="stylesheet" href="{{ asset('css/shop.css') }}">
</head>
<body>
<header class="topbar">
    <a href="/shop" class="brand"><span class="brand-icon">N</span><span>{{ $shopName }}</span></a>
    <nav><a href="#catalog">Shop products</a><a href="#how-it-works">How it works</a></nav>
    <button id="cart-toggle" class="cart-toggle" type="button" aria-label="Open cart">Bag <span id="cart-count">0</span></button>
</header>
<main>
    <section class="hero">
        <div class="hero-copy"><div class="eyebrow">CONNECTED TO LIVE CIMS STOCK</div>
            <h1>Everything your project needs, in one place.</h1>
            <p>Browse hardware and building supplies with availability drawn directly from our inventory. Order now for delivery or store pickup.</p>
            <a class="button button-dark" href="#catalog">Explore products <span aria-hidden="true">↗</span></a>
        </div>
        <div class="hero-art" aria-hidden="true"><div class="art-grid"></div><div class="art-card art-one">BUILT<br>TO LAST<span>01 / 03</span></div><div class="art-card art-two">PROJECT<br>READY<span>02 / 03</span></div><div class="art-disc">+</div></div>
    </section>
    <section class="promise" id="how-it-works"><div><strong>01</strong><span>Live stock availability</span></div><div><strong>02</strong><span>Secure order checkout</span></div><div><strong>03</strong><span>Clear order tracking</span></div></section>
    <section class="catalog" id="catalog">
        <div class="section-title"><div><div class="eyebrow">THE COLLECTION</div><h2>Products for the job</h2></div><p>Prices in Philippine pesos. Stock updates as orders are placed.</p></div>
        @if (!$ready)
            <div class="empty-state">The online shop is being prepared. Please check back soon.</div>
        @else
            <div class="filters"><label class="search"><span>⌕</span><input id="search" type="search" placeholder="Search product or code" autocomplete="off"></label><select id="category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><span id="result-count"></span></div>
            <div id="catalog-status" class="catalog-status" role="status"></div>
            <div id="product-grid" class="product-grid"></div>
            <button type="button" id="load-more" class="button button-outline" hidden>Load more products</button>
        @endif
    </section>
</main>
<footer><span class="brand"><span class="brand-icon">N</span>{{ $shopName }}</span><span>Order from our live inventory.</span><span>© {{ date('Y') }} {{ $shopName }}</span></footer>
@if ($ready)
<div id="drawer-overlay" class="drawer-overlay" hidden></div>
<aside id="cart-drawer" class="cart-drawer" aria-label="Shopping bag" aria-hidden="true">
    <div class="drawer-head"><div><div class="eyebrow">YOUR SELECTION</div><h2>Shopping bag</h2></div><button id="cart-close" class="icon-button" type="button" aria-label="Close cart">×</button></div>
    <div id="cart-items" class="cart-items"></div>
    <div class="drawer-bottom"><div class="total"><span>Subtotal</span><strong id="cart-total">₱0.00</strong></div><p>Delivery fees, if any, are arranged when your order is confirmed.</p><button id="checkout-open" class="button button-dark full" type="button">Continue to checkout →</button></div>
</aside>
<div id="checkout-overlay" class="modal-overlay" hidden><section class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
    <button id="checkout-close" class="icon-button modal-close" type="button" aria-label="Close checkout">×</button><div class="eyebrow">ONE STEP AWAY</div><h2 id="checkout-title">Place your order</h2><p>We will reserve available stock as soon as you submit.</p>
    <form id="checkout-form"><div class="form-grid"><label>Full name<input name="name" required minlength="2" maxlength="192" autocomplete="name"></label><label>Email<input name="email" type="email" required autocomplete="email"></label><label>Phone<input name="phone" required minlength="7" maxlength="40" autocomplete="tel"></label><label>City<input name="city" required minlength="2" maxlength="100" autocomplete="address-level2"></label><label class="wide">Delivery address<input name="address" required minlength="5" maxlength="500" autocomplete="street-address"></label><label class="wide">Order note <small>(optional)</small><textarea name="notes" rows="2" maxlength="1000"></textarea></label></div>
        <fieldset><legend>Payment method</legend><label class="radio"><input type="radio" name="payment_method" value="cash_on_delivery" checked> Cash on delivery</label><label class="radio"><input type="radio" name="payment_method" value="pay_in_store"> Pay in store</label>@if($xenditReady)<label class="radio"><input type="radio" name="payment_method" value="xendit"> Pay online with Xendit</label>@endif</fieldset>
        <label class="radio preorder"><input type="checkbox" name="allow_preorder" value="1"> Allow a clearly marked pre-order if stock runs out</label><p class="fineprint">Online payment requires all items in stock. Pre-orders can use cash on delivery or pay in store.</p>
        <div id="checkout-error" class="error" role="alert" hidden></div><button id="place-order" class="button button-dark full" type="submit">Place order</button>
    </form></section></div>
<script src="{{ asset('js/shop.js') }}" defer></script>
@endif
</body></html>
