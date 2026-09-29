jQuery(function ($) {

    let quote = JSON.parse(localStorage.getItem('woo_quote') || '{}');

    function saveQuote() {
        localStorage.setItem('woo_quote', JSON.stringify(quote));
        renderTable();
        updateQuoteCounter();
    }

    function renderTable() {
        let tbody = $('.woo-quote-table tbody');
        tbody.empty();

        if (Object.keys(quote).length === 0) {
            $('.quote-table, .customer-details').remove();
            $('.woo-quote-wrapper').before("<p>No products added to the quote list.<br /><a class='button mt-2' href='/product-category/shelving-range/'>Return to Shop</a></p>");
        } else {
            $.each(quote, function (key, item) {
                $('.quote-table, .customer-details').removeClass('d-none');

                let price = parseFloat(item.price) || 0;
                let qty   = parseInt(item.qty, 10) || 1;
                let subtotal = (price * qty).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

                tbody.append(`
                    <tr>
                        <td class="product-thumbnail">
                            <img src="${item.image}" alt="${item.name}" width="60">
                        </td>

                        <td class="product-name">${item.variation_name ? item.name + ' - ' + item.variation_name : item.name}</td>

                        <td class="product-price">
                            <span class="woocommerce-Price-amount">
                                ${price.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                            </span>
                        </td>

                        <td class="product-quantity">
                            <div class="quantity buttons_added extra-type">
                                <button type="button" class="minus">-</button>
                                <input
                                    type="number"
                                    class="input-text qty text woo-quote-qty"
                                    step="1"
                                    min="1"
                                    value="${qty}"
                                    data-key="${key}"
                                    inputmode="numeric"
                                >
                                <button type="button" class="plus">+</button>
                            </div>
                        </td>

                        <td class="product-subtotal">
                            <span class="woocommerce-Price-amount">
                                ${subtotal}
                            </span>
                        </td>

                        <td class="product-remove">
                            <a href="#" class="remove woo-quote-remove" data-key="${key}">×</a>
                        </td>
                    </tr>
                `);
            });
        }
    }


    // Add to Quote
    $(document).on('click', '.woo-add-to-quote', function (e) {
        e.preventDefault();

        let $btn = $(this);
        let item = {};

        let id    = $btn.attr('data-product-id');
        let name  = $btn.attr('data-product-name');
        let image = $btn.attr('data-product-image');

        let price = parseFloat($btn.attr('data-product-price')) || 0;

        let qty = parseInt(
            $btn.closest('form.cart, .product, .summary')
                .find('.quantity .qty')
                .val() || 1,
            10
        );

        let variation_id   = $btn.attr('data-variation-id') || null;
        let variation_name = $btn.attr('data-variation-name') || null;

        item = {
            product_id: id,
            variation_id: variation_id,
            variation_name: variation_name,
            name: name,
            image: image,
            price: price,
            qty: qty
        };

        let key = item.variation_id
            ? `${item.product_id}_${item.variation_id}`
            : String(item.product_id);

        if (!quote[key]) {
            quote[key] = item;
        } else {
            quote[key].qty += qty;
        }
        
        saveQuote();

        let quoteMessage = `
            <div class="added_quote no-cart">
                Product added to your quote list!<br>
                <a href="/quote-request/">View Quote Request</a>
            </div>
        `;

        if ($btn.hasClass('alt')) {
            let $productContent = $(this).closest('.product-content');
            let $priceSpan = $productContent.find('span.price');
            $productContent.find('.added_quote_notice').remove();
            $priceSpan.before(quoteMessage);
        }
        else {
            quoteMessage = `
                <div class="woocommerce-message" role="alert" tabindex="-1">
                    <strong class="single-cart-notice">“${name}”</strong> <span class="font-weight-medium">has been added to your quote list.</span>
                </div>
            `;
            $('.woocommerce-notices-wrapper').html(quoteMessage);
        }
    });


    // Plus
    $(document).on('click', '.quantity .plus', function () {
        let $qty = $(this).siblings('.qty');
        let current = parseFloat($qty.val());

        if (!isNaN(current)) {
            $qty.val(current + 1).trigger('change');
        }
    });

    // Minus
    $(document).on('click', '.quantity .minus', function () {
        let $qty = $(this).siblings('.qty');
        let current = parseFloat($qty.val());

        if (!isNaN(current) && current > 1) {
            $qty.val(current - 1).trigger('change');
        }
    });

    // Qty change
    $(document).on('change', '.woo-quote-qty', function () {
        let key = $(this).data('key');
        let qty = parseInt(this.value, 10);

        quote[key].qty = qty > 0 ? qty : 1;
        saveQuote();
    });

    // Variation change
    $(document).on('change', '#pa_size, #size', function () {
        const $select = $(this);
        const $button = $('.woo-add-to-quote');

        setTimeout(function() {
            let variation_id = $('input[name="variation_id"]').val();
            let variation_name = $select.find('option:selected').text();
            
            let priceElement = $('.summary .woocommerce-Price-amount').last();
            let price = priceElement.text().replace(/[^\d.]/g, '');

            if ($select.val() === "") {
                variation_id = "";
                variation_name = "";
            }

            $button.attr('data-variation-id', variation_id)
                .attr('data-variation-name', variation_name)
                .attr('data-product-price', price);

            console.log("Captured Variation:", variation_id, "Name:", variation_name, "Price:", price);
        }, 400);
    });

    // Remove
    $(document).on('click', '.woo-quote-remove', function (e) {
        e.preventDefault();
        delete quote[$(this).data('key')];
        saveQuote();
    });

    // Submit quote
    $(document).on('submit', '.woo-quote-form', function (e) {
        e.preventDefault();

        let $form = $(this);
        let $submitBtn = $form.find('#place_order');
        let originalBtnText = $submitBtn.html();

        $submitBtn
            .prop('disabled', true)
            .addClass('processing')
            .html('<i class="fas fa-spinner fa-spin"></i> Sending Request...');

        $.post(wooQuote.ajax, {
            action: 'woo_submit_quote',
            nonce: wooQuote.nonce,
            items: JSON.stringify(quote),
            first_name: $('[name=first_name]').val(),
            last_name: $('[name=last_name]').val(),
            email: $('[name=email]').val(),
            company: $('[name=company]').val(),
            state: $('[name=state]').val(),
            city: $('[name=city]').val(),
            address_1: $('[name=address_1]').val(),
            address_2: $('[name=address_2]').val(),
            postcode: $('[name=postcode]').val(),
            phone: $('[name=phone]').val(),
            note: $('[name=note]').val()
        }, function (res) {
            if (res.success) {
                $('.quote-table, .customer-details, .woo-quote-count').remove();
                $('.woo-quote-wrapper').before("<p>Your quote request has been sent.<br /><a class='button mt-2' href='/product-category/shelving-range/'>Return to Shop</a></p>");
                localStorage.removeItem('woo_quote');
                resetButton($submitBtn, originalBtnText);
                } else {
                alert('Error: ' + res.data.message);
                resetButton($submitBtn, originalBtnText);
            }
        }).fail(function() {
            // Handle physical connection errors
            alert('There was a connection error. Please try again.');
            resetButton($submitBtn, originalBtnText);
        });

        // Helper function to reset button
        function resetButton(btn, text) {
            btn.prop('disabled', false).removeClass('processing').html(text);
        }
    });

    function updateQuoteCounter() {
        let quote = {};

        try {
            quote = JSON.parse(localStorage.getItem('woo_quote')) || {};
        } catch (e) {
            quote = {};
        }

        let count = 0;

        $.each(quote, function (key, item) {
            count += parseInt(item.qty, 10) || 0;
        });

        $('.woo-quote-count').text(count).toggle(count > 0);
    }

    renderTable();
    updateQuoteCounter();
});
