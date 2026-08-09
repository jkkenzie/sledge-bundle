;(($) => {
  // Ensure wcComboProduct is available with fallbacks
  const wcComboProduct = window.wcComboProduct || {}
  const wc_add_to_cart_params = window.wc_add_to_cart_params || {}

  if (typeof wcComboProduct === "undefined") {
    console.error("WC Combo: wcComboProduct not loaded")
    return
  }

  // Use the updated localized values from Plugin.php with validation
  const ajaxUrl = wcComboProduct.ajaxUrl
  const nonce = wcComboProduct.addComboToCartNonce
  const currencySymbol = wcComboProduct.currency_symbol
  const currencyPosition = wcComboProduct.currency_position
  const thousandSeparator = wcComboProduct.thousand_separator
  const decimalSeparator = wcComboProduct.decimal_separator
  const decimals = wcComboProduct.decimals

  // Function to show WordPress-style messages
  function showMessage(message, type = "info") {
    const messageContainer = $("#combo-messages")
    const messageClass = type === "error" ? "notice-error" : type === "warning" ? "notice-warning" : "notice-success"

    const messageHtml = `
      <div class="notice ${messageClass} is-dismissible">
        <p>${message}</p>
        <button type="button" class="notice-dismiss">
          <span class="screen-reader-text">Dismiss this notice.</span>
        </button>
      </div>
    `

    messageContainer.html(messageHtml).show()

    // Auto-hide success messages after 5 seconds
    if (type === "success") {
      setTimeout(() => {
        messageContainer.fadeOut()
      }, 5000)
    }

    // Scroll to message only if container exists and is visible
    if (messageContainer.length && messageContainer.is(":visible")) {
      $("html, body").animate(
        {
          scrollTop: messageContainer.offset().top - 100,
        },
        500,
      )
    }
  }

  // Handle notice dismiss
  $(document).on("click", ".notice-dismiss", function () {
    $(this).closest(".notice").fadeOut()
  })

  // FIXED: Function to check if removal is allowed
  function canRemoveItem($row) {
    const isOptional = $row.data("optional") === 1

    // If item is not optional (i.e., required), it cannot be removed
    if (!isOptional) {
      return { allowed: false, reason: "This is a required item and cannot be removed." }
    }

    // Count remaining visible items after this removal
    const visibleRows = $("#combo_products_table tbody tr.combo_products_tr:visible").not($row)

    // Must have at least one item remaining (prevent removal of last item)
    if (visibleRows.length === 0) {
      return { allowed: false, reason: "Cannot remove the last item from the combo." }
    }

    // Check if there are any required items that must remain
    const requiredRows = visibleRows.filter('[data-optional="0"]') // Use data-optional instead of data-required
    const hasRequiredItems =
      $("#combo_products_table tbody tr.combo_products_tr:visible").filter('[data-optional="0"]').length > 0

    // If there are required items in the combo, at least one must remain
    if (hasRequiredItems && requiredRows.length === 0) {
      return { allowed: false, reason: "At least one required item must remain in the combo." }
    }

    return { allowed: true, reason: "" }
  }

  // Enhanced Add to Cart with improved View Cart button handling
  $(document.body).on("click", ".combo_add_to_cart_button", function (e) {
    e.preventDefault()
    const $this = $(this)

    // Skip if this is a GET form (simple add to cart)
    if ($this.closest("form").attr("method") == "get") {
      return true
    }

    const $form = $this.closest("form.cart")
    const id = $this.val()
    const product_qty = $form.find("input[name=quantity]").val() || 1
    const product_id = $form.find("input[name=product_id]").val() || id

    // Stop early if product id is missing
    if (!product_id) {
      return true
    }

    // Check for max quantity validation
    const max = Number.parseInt($form.find("input.qty").attr("max"))
    const qty = Number.parseInt($form.find("input.qty").val())

    if (!!max && max < qty) {
      alert("Value must be less than or equal to " + max)
      return
    }

    // If already added to cart, go to cart
    if ($this.hasClass("cart-added")) {
      if (typeof wc_add_to_cart_params !== "undefined") {
        window.location = wc_add_to_cart_params.cart_url
      }
      return false
    }

    // Skip gift card forms
    if ($form.find(".woocommerce_gc_giftcard_form").length) {
      return true
    }

    const comboItems = []
    // Gather combo items data from the DOM
    $("#combo_products_table tbody tr").each(function () {
      const variations = {}
      const variation_id = $(this).find("input[name=variation_id]").val() || 0
      $(this)
        .find("select")
        .each(function () {
          variations[$(this).attr("name")] = $(this).val()
        })
      const comboItem = {
        product_id: $(this).attr("id"),
        quantity: $(this).find("input.input-text.qty").val(),
        variations: variations,
        variation_id: variation_id,
      }
      comboItems.push(comboItem)
    })

    const data = {
      action: "add_combo_to_cart",
      product_id: product_id,
      combo_items: comboItems,
      product_sku: "",
      quantity: product_qty,
      nonce: wcComboProduct.addComboToCartNonce, // Use the correct nonce key
    }

    // Add reservation data if checkbox is checked
    if ($("#combo_reservation").is(":checked")) {
      data.combo_reservation = true
    }

    $(document.body).trigger("adding_to_cart", [$this, data])

    let eventTriggered = false

    $.ajax({
      type: "post",
      url: wcComboProduct.ajaxUrl, // Use the correct AJAX URL
      data: data,
      beforeSend: (response) => {
        $this.removeClass("added").addClass("loading")
      },
      complete: (response) => {
        $this.addClass("added").removeClass("loading")
      },
      success: (response) => {
        if (response.error && response.product_url) {
          window.location = response.product_url
          return
        } else {
          const fragments = response.data ? response.data.fragments : response.fragments
          const cart_hash = response.data ? response.data.cart_hash : response.cart_hash

          if (!eventTriggered && fragments && typeof fragments === "object") {
            eventTriggered = true
            $(document.body).trigger("added_to_cart", [fragments, cart_hash, $this])
          }

          if ($this.hasClass("zoo-buy-now")) {
            window.location = $this.attr("direct_link")
            return false
          }
        }
      },
    })
  })

  // Listen for changes in quantity inputs within combo products table (only for enabled inputs)
  $(document).on(
    "change",
    "#combo_products_table input.qty:not(.qty-disabled):not([readonly]):not(.out-of-stock)",
    function () {
      const $input = $(this)
      const $row = $input.closest("tr")

      // Get the quantity value from the input
      let qty = Number.parseFloat($input.val()) || 0

      // For required items, ensure minimum quantity of 1
      const isOptional = $row.data("optional") === 1 // FIXED: Use data-optional
      if (!isOptional && qty < 1) {
        qty = 1
        $input.val(qty)
        showMessage("Required items must have a minimum quantity of 1.", "warning")
      }

      // Get max value and enforce it
      const maxValue = Number.parseInt($input.attr("max"))
      if (maxValue && qty > maxValue) {
        showMessage("Maximum quantity allowed is " + maxValue, "warning")
        $input.val(maxValue)
        return
      }

      // Get the single price from the data-single attribute
      const $priceInput = $row.find("input.combo_product_price")
      const singlePrice = Number.parseFloat($priceInput.data("single")) || 0

      // Calculate the total price for this row
      const totalPrice = qty * singlePrice

      // Update the price input value
      $priceInput.val(totalPrice.toFixed(decimals))

      // Update the displayed price in the same row
      $row.find("span.woocommerce-Price-amount.amount").each(function () {
        const $priceSpan = $(this)

        // Format the price with proper currency symbol and position
        const formattedPrice = formatPrice(totalPrice)
        $priceSpan.html(formattedPrice)
      })

      // Update the total price and discount
      updateTotalPriceAndDiscount()
    },
  )

  // Prevent manual input of values exceeding max quantity and enforce minimum for required items
  $(document).on(
    "input",
    "#combo_products_table input.qty:not(.qty-disabled):not([readonly]):not(.out-of-stock)",
    function () {
      const $input = $(this)
      const $row = $input.closest("tr")
      const maxValue = Number.parseInt($input.attr("max"))
      const currentValue = Number.parseInt($input.val())
      const isOptional = $row.data("optional") === 1 // FIXED: Use data-optional

      if (maxValue && currentValue > maxValue) {
        $input.val(maxValue)
      }

      // For required items, enforce minimum of 1
      if (!isOptional && currentValue < 1) {
        $input.val(1)
      }
    },
  )

  function updateTotalPriceAndDiscount() {
    let totalPrice = 0
    let totalDiscount = 0

    // Calculate prices and discounts for ALL rows (including hidden ones) to maintain correct totals
    $("#combo_products_table tbody tr.combo_products_tr .combo_product_price").each(function () {
      const $priceInput = $(this)
      const singlePrice = Number.parseFloat($priceInput.data("single")) || 0
      const originalPrice = Number.parseFloat($priceInput.data("original")) || singlePrice
      const $row = $priceInput.closest("tr")
      const quantity = Number.parseInt($row.find(".input-text.qty").val()) || 0

      const lineTotal = singlePrice * quantity
      const originalLineTotal = originalPrice * quantity
      const lineDiscount = originalLineTotal - lineTotal

      totalPrice += lineTotal
      totalDiscount += lineDiscount
    })

    // Update discount row
    const $discountRow = $("#combo_products_table .combo-discount-row")
    if (totalDiscount > 0) {
      if ($discountRow.length) {
        $discountRow.find(".discount-amount").html("<strong>-" + formatPrice(totalDiscount) + "</strong>")
      } else {
        // Add discount row if it doesn't exist
        const discountRowHtml = `
          <tr class="combo-discount-row">
            <td colspan="3" class="discount-label"><strong>Total Discount:</strong></td>
            <td class="discount-amount"><strong>-${formatPrice(totalDiscount)}</strong></td>
          </tr>
        `
        $("#combo_products_table tbody").append(discountRowHtml)
      }
    } else {
      $discountRow.remove()
    }

    // Update the total price display in the product summary
    const $totalPriceElement = $(".product-summary-side .woocommerce-Price-amount bdi")

    if ($totalPriceElement.length) {
      const formattedPrice = formatPrice(totalPrice)
      $totalPriceElement.html(formattedPrice)
    }
  }

  function formatPrice(amount) {
    // Ensure amount is a valid number
    if (isNaN(amount) || amount === null || amount === undefined) {
      amount = 0
    }

    // Format number with proper decimal places
    const formattedNumber = Number(amount).toFixed(decimals)

    // Split into integer and decimal parts
    const parts = formattedNumber.split(".")
    const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator)
    const decimalPart = parts[1]

    // Combine with decimal separator
    const finalNumber = decimals > 0 ? integerPart + decimalSeparator + decimalPart : integerPart

    // Apply currency symbol and position
    let formattedPrice = ""

    switch (currencyPosition) {
      case "left":
        formattedPrice = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + "</span>" + finalNumber
        break
      case "right":
        formattedPrice = finalNumber + '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + "</span>"
        break
      case "left_space":
        formattedPrice = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + "</span> " + finalNumber
        break
      case "right_space":
        formattedPrice = finalNumber + ' <span class="woocommerce-Price-currencySymbol">' + currencySymbol + "</span>"
        break
      default:
        formattedPrice = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + "</span>" + finalNumber
    }

    return formattedPrice
  }

  // Handle remove bundle item with enhanced validation for optional behavior
  $(document).on("click", ".RemoveBundleItem:not(.disabled)", function (e) {
    e.preventDefault()

    const $row = $(this).closest("tr")
    const removalCheck = canRemoveItem($row)

    if (!removalCheck.allowed) {
      showMessage(removalCheck.reason, "warning")
      return false
    }

    if (!confirm("Are you sure you want to remove this item from the list?")) {
      return
    }

    // Add visual feedback
    $row.css({
      "box-shadow": "none",
      "background-color": "#fefefe",
      "font-size": "18px",
      color: "#000",
    })

    // Show removal message
    $row.html(
      `<td colspan="4" align="center" valign="middle">
      <div id="combo-messages" class="combo-messages" style="">
      <div class="notice notice-warning is-dismissible">
        <p>To Add Alternative Product, Add to cart below and continue shopping</p>
        <button type="button" class="notice-dismiss">
          <span class="screen-reader-text">Dismiss this notice.</span>
        </button>
      </div>
    </div>
      </td>`,
    )

    // Update total price and discount after removal
    updateTotalPriceAndDiscount()

    // Remove the row after delay
    setTimeout(() => {
      $row.remove()
      updateTotalPriceAndDiscount() // Update again after removal
    }, 4000)
  })

  // Initialize total price calculation on page load
  $(document).ready(() => {

    const hasComboTable = document.getElementById('combo_products_table');
    if (!hasComboTable) {
      return; // Not a combo product page; do nothing
    }
    
    updateTotalPriceAndDiscount()

    // Add visual indicators for disabled quantity inputs
    $("#combo_products_table input.qty[readonly], #combo_products_table input.qty.qty-disabled").each(function () {
      $(this).closest(".quantity").addClass("qty-disabled")
    })

    // Set up max quantity validation on existing inputs
    $("#combo_products_table input.qty").each(function () {
      const $input = $(this)
      const maxValue = Number.parseInt($input.attr("max"))

      if (maxValue && maxValue > 0) {
        // Add visual indicator for max quantity
        const $container = $input.closest("td")
        if (!$container.find(".max-qty-notice").length) {
          $container.append(
            '<small class="max-qty-notice" style="display: block; color: #666; font-size: 11px;">Max: ' +
              maxValue +
              "</small>",
          )
        }
      }
    })

    // Initialize out of stock styling
    $("#combo_products_table tr.out-of-stock").each(function () {
      const $row = $(this)
      $row.addClass("grayscale")
      $row.find("input, button, a").prop("disabled", true).addClass("disabled")
    })

    // FIXED: Initialize required item styling using data-optional
    $("#combo_products_table tr[data-optional='0']").each(function () {
      const $row = $(this)
      $row.addClass("required-item")

      // Ensure required items have minimum quantity of 1
      const $qtyInput = $row.find("input.qty")
      if (Number.parseInt($qtyInput.val()) < 1) {
        $qtyInput.val(1)
      }
    })

    if ($("body").hasClass("woocommerce-checkout")) {
      // Update reservation amounts on checkout updates (fires when totals change for any reason)
      $(document.body).on("updated_checkout", () => {
        try {
          updateReservationAmounts()?.catch?.((error) => {
            console.warn("WC Combo: Reservation update failed:", error)
          })
        } catch (error) {
          console.warn("WC Combo: Error in checkout update handler:", error)
        }
      })

      // Initial load check for reservation amounts with delay and error handling
      setTimeout(() => {
        try {
          updateReservationAmounts()?.catch?.((error) => {
            console.warn("WC Combo: Initial reservation update failed:", error)
          })
        } catch (error) {
          console.warn("WC Combo: Error in initial reservation update:", error)
        }
      }, 1000)
    }
  })

  function updateReservationAmounts() {
    const $reservationContainer = $("#combo-reservation-container")
    if ($reservationContainer.length === 0) return

    const ajaxData = {
      ajax_url: wcComboProduct.reservation_ajax_url || wcComboProduct.ajaxUrl,
      nonce: wcComboProduct.reservation_nonce || wcComboProduct.addComboToCartNonce,
    }

    // Validate required data before making AJAX call
    if (!ajaxData.ajax_url || !ajaxData.nonce) {
      console.warn("WC Combo: Missing AJAX data for reservation updates")
      return
    }

    return $.ajax({
      type: "POST",
      url: ajaxData.ajax_url,
      data: {
        action: "update_reservation_amounts",
        nonce: ajaxData.nonce,
      },
      timeout: 10000, // 10 second timeout
    })
      .done((response) => {
        try {
          if (response && response.success && response.data) {
            // Update individual elements instead of replacing entire container
            const data = response.data
            const $percentage = $("#reservation-percentage")
            const $deposit = $("#reservation-deposit")
            const $balance = $("#reservation-balance")

            if ($percentage.length) $percentage.text(data.percentage || "")
            if ($deposit.length) $deposit.html(data.deposit || "")
            if ($balance.length) $balance.html(data.balance || "")

            // Show container if it was hidden due to minimum amount
            $reservationContainer.show()
          }
        } catch (error) {
          console.warn("WC Combo: Error processing reservation response:", error)
        }
      })
      .fail((xhr, status, error) => {
        try {
          if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
            // Hide reservation container if cart doesn't meet requirements
            const errorData = xhr.responseJSON.data
            if (errorData === "Cart total below minimum" || errorData === "No eligible products in cart") {
              $reservationContainer.hide()
            }
          }
        } catch (e) {
          console.warn("WC Combo: Error handling reservation failure:", e)
        }
      })
      .always(() => {
        // Cleanup or final actions if needed
      })
  }
})(window.jQuery)
