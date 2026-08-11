;(($) => {
  const wcComboProduct = window.wcComboProduct || {}
  const wc_add_to_cart_params = window.wc_add_to_cart_params || {}
  const i18n = wcComboProduct.i18n || {}

  if (!wcComboProduct.ajaxUrl) {
    return
  }

  const currencySymbol = wcComboProduct.currency_symbol || ""
  const currencyPosition = wcComboProduct.currency_position || "left"
  const thousandSeparator = wcComboProduct.thousand_separator || ","
  const decimalSeparator = wcComboProduct.decimal_separator || "."
  const decimals = Number(wcComboProduct.decimals ?? 2)

  function showMessage(message, type = "info") {
    const messageContainer = $("#combo-messages")
    if (!messageContainer.length) {
      return
    }

    const messageClass =
      type === "error" ? "notice-error" : type === "warning" ? "notice-warning" : "notice-success"

    messageContainer
      .prop("hidden", false)
      .show()
      .html(
        `<div class="notice ${messageClass} is-dismissible"><p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>`,
      )
  }

  $(document).on("click", ".notice-dismiss", function () {
    $(this).closest(".notice").fadeOut()
  })

  function visibleItems() {
    return $("#combo_products_table .combo_products_tr:visible")
  }

  function canRemoveItem($row) {
    const isOptional = Number($row.data("optional")) === 1
    if (!isOptional) {
      return { allowed: false, reason: i18n.removeRequired || "This is a required item and cannot be removed." }
    }

    const remaining = visibleItems().not($row)
    if (remaining.length === 0) {
      return { allowed: false, reason: i18n.removeLast || "Cannot remove the last item from the bundle." }
    }

    const hadRequired = visibleItems().filter('[data-optional="0"]').length > 0
    const remainingRequired = remaining.filter('[data-optional="0"]').length
    if (hadRequired && remainingRequired === 0) {
      return {
        allowed: false,
        reason: i18n.removeRequiredMin || "At least one required item must remain in the bundle.",
      }
    }

    return { allowed: true, reason: "" }
  }

  function formatPrice(amount) {
    if (isNaN(amount) || amount === null || amount === undefined) {
      amount = 0
    }

    const formattedNumber = Number(amount).toFixed(decimals)
    const parts = formattedNumber.split(".")
    const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator)
    const decimalPart = parts[1]
    const finalNumber = decimals > 0 ? integerPart + decimalSeparator + decimalPart : integerPart
    const symbol = `<span class="woocommerce-Price-currencySymbol">${currencySymbol}</span>`

    switch (currencyPosition) {
      case "right":
        return finalNumber + symbol
      case "left_space":
        return symbol + " " + finalNumber
      case "right_space":
        return finalNumber + " " + symbol
      case "left":
      default:
        return symbol + finalNumber
    }
  }

  function updateLineSubtotal($row) {
    const qty = Number.parseFloat($row.find("input.qty").val()) || 0
    const $priceInput = $row.find("input.combo_product_price")
    const singlePrice = Number.parseFloat($priceInput.data("single")) || Number.parseFloat($row.data("unit-price")) || 0
    const totalPrice = qty * singlePrice

    $priceInput.val(totalPrice.toFixed(decimals))
    $row.find("[data-line-subtotal], .combo-item__subtotal .woocommerce-Price-amount").first().html(formatPrice(totalPrice))
  }

  function updateTotalPriceAndDiscount() {
    let totalPrice = 0

    visibleItems().each(function () {
      const $row = $(this)
      const $priceInput = $row.find("input.combo_product_price")
      const singlePrice = Number.parseFloat($priceInput.data("single")) || Number.parseFloat($row.data("unit-price")) || 0
      const quantity = Number.parseInt($row.find("input.qty").val(), 10) || 0
      totalPrice += singlePrice * quantity
    })

    const formatted = formatPrice(totalPrice)
    $("[data-combo-total]").html(formatted)
    $(".sledge-combo-price .woocommerce-Price-amount").not("[data-line-subtotal]").html(formatted)
    $(".product-summary-side .price .woocommerce-Price-amount").first().html(formatted)
  }

  $(document.body).on("click", ".combo_add_to_cart_button", function (e) {
    e.preventDefault()
    const $this = $(this)

    if ($this.closest("form").attr("method") == "get") {
      return true
    }

    const $form = $this.closest("form.cart")
    const id = $this.val()
    const product_qty = $form.find("input[name=quantity]").val() || 1
    const product_id = $form.find("input[name=product_id]").val() || id

    if (!product_id) {
      return true
    }

    const max = Number.parseInt($form.find("input.qty").first().attr("max"), 10)
    const qty = Number.parseInt($form.find("input.qty").first().val(), 10)
    if (!!max && max < qty) {
      alert((i18n.maxQty || "Maximum quantity allowed is %s").replace("%s", max))
      return
    }

    if ($this.hasClass("cart-added")) {
      if (typeof wc_add_to_cart_params !== "undefined" && wc_add_to_cart_params.cart_url) {
        window.location = wc_add_to_cart_params.cart_url
      }
      return false
    }

    const comboItems = []
    visibleItems().each(function () {
      const $row = $(this)
      const variations = {}
      const variation_id = $row.find("input[name=variation_id]").val() || 0
      $row.find("select").each(function () {
        variations[$(this).attr("name")] = $(this).val()
      })
      comboItems.push({
        product_id: $row.attr("id") || $row.data("product-id"),
        quantity: $row.find("input.qty").val(),
        variations: variations,
        variation_id: variation_id,
      })
    })

    if (!comboItems.length) {
      showMessage(i18n.removeLast || "Cannot remove the last item from the bundle.", "warning")
      return
    }

    const data = {
      action: "add_combo_to_cart",
      product_id: product_id,
      combo_items: comboItems,
      product_sku: "",
      quantity: product_qty,
      nonce: wcComboProduct.addComboToCartNonce,
    }

    if ($("#combo_reservation").is(":checked")) {
      data.combo_reservation = true
    }

    $(document.body).trigger("adding_to_cart", [$this, data])

    let eventTriggered = false

    $.ajax({
      type: "post",
      url: wcComboProduct.ajaxUrl,
      data: data,
      beforeSend: () => {
        $this.removeClass("added").addClass("loading")
      },
      complete: () => {
        $this.addClass("added").removeClass("loading")
      },
      success: (response) => {
        if (response.error && response.product_url) {
          window.location = response.product_url
          return
        }

        const fragments = response.data ? response.data.fragments : response.fragments
        const cart_hash = response.data ? response.data.cart_hash : response.cart_hash

        if (!eventTriggered && fragments && typeof fragments === "object") {
          eventTriggered = true
          $(document.body).trigger("added_to_cart", [fragments, cart_hash, $this])
        }

        if ($this.hasClass("zoo-buy-now")) {
          window.location = $this.attr("direct_link")
        }
      },
    })
  })

  $(document).on(
    "change input",
    "#combo_products_table input.qty:not(.qty-disabled):not([readonly]):not(.out-of-stock)",
    function () {
      const $input = $(this)
      const $row = $input.closest(".combo_products_tr")
      let qty = Number.parseFloat($input.val()) || 0
      const isOptional = Number($row.data("optional")) === 1

      if (!isOptional && qty < 1) {
        qty = 1
        $input.val(qty)
        showMessage(i18n.minQtyRequired || "Required items must have a minimum quantity of 1.", "warning")
      }

      const maxValue = Number.parseInt($input.attr("max"), 10)
      if (maxValue && qty > maxValue) {
        showMessage((i18n.maxQty || "Maximum quantity allowed is %s").replace("%s", String(maxValue)), "warning")
        $input.val(maxValue)
        qty = maxValue
      }

      updateLineSubtotal($row)
      updateTotalPriceAndDiscount()
    },
  )

  $(document).on("click", ".RemoveBundleItem:not(.disabled)", function (e) {
    e.preventDefault()

    const $row = $(this).closest(".combo_products_tr")
    const removalCheck = canRemoveItem($row)

    if (!removalCheck.allowed) {
      showMessage(removalCheck.reason, "warning")
      return false
    }

    if (!window.confirm(i18n.removeConfirm || "Remove this item from the bundle?")) {
      return
    }

    $row.addClass("is-removing")
    setTimeout(() => {
      $row.remove()
      updateTotalPriceAndDiscount()
      showMessage(i18n.itemRemoved || "Item removed. Bundle total updated.", "success")
    }, 180)
  })

  $(document).ready(() => {
    initFloatingSummary()

    if (!document.getElementById("combo_products_table")) {
      return
    }

    visibleItems().each(function () {
      updateLineSubtotal($(this))
    })
    updateTotalPriceAndDiscount()

    $("#combo_products_table .combo_products_tr[data-optional='0']").each(function () {
      const $qtyInput = $(this).find("input.qty")
      if (Number.parseInt($qtyInput.val(), 10) < 1) {
        $qtyInput.val(1)
      }
    })

    if ($("body").hasClass("woocommerce-checkout")) {
      $(document.body).on("updated_checkout", () => {
        try {
          updateReservationAmounts()?.catch?.(() => {})
        } catch (error) {
          // ignore
        }
      })

      setTimeout(() => {
        try {
          updateReservationAmounts()?.catch?.(() => {})
        } catch (error) {
          // ignore
        }
      }, 1000)
    }
  })

  /**
   * Fixed floating summary — ships with the plugin.
   * Behaves like sticky inside the parent column:
   * - never rises above the column top (respects .site-content header padding)
   * - pins under the fixed header while scrolling
   * - clamps to the column bottom without collapsing height / un-fixing
   */
  function initFloatingSummary() {
    const product = document.querySelector(".sledge-combo-product")
    const summary = document.querySelector(".sledge-combo-summary.product-summary-side")
    if (!product || !summary) {
      return
    }

    const inner = summary.querySelector(".sledge-combo-summary__inner")
    if (!inner) {
      return
    }

    const layout = product.querySelector(".sledge-combo-layout") || product
    const mq = window.matchMedia("(max-width: 960px)")
    let scheduled = false
    let placeholder = null
    const gap = 12
    const bottomPad = 12

    function readCssPx(value) {
      const n = parseFloat(value)
      return Number.isFinite(n) ? n : 0
    }

    /**
     * Pin offset under fixed chrome.
     * Prefer live header edges + --rg-sticky-top (updates on scroll).
     * Do not use .site-content padding-top here — that stays at resting
     * header height and would over-pin while scrolled; column-top clamp
     * already keeps the panel below the content padding on initial load.
     */
    function pinOffset() {
      const root = getComputedStyle(document.documentElement)
      const stickyTop = readCssPx(root.getPropertyValue("--rg-sticky-top"))
      const resting =
        readCssPx(root.getPropertyValue("--topbar-h")) +
        readCssPx(root.getPropertyValue("--nav-h"))
      const fromVars = stickyTop > 0 ? stickyTop : resting

      let fromHeader = 0
      const known = [
        document.getElementById("topbar"),
        document.getElementById("navbar"),
        document.getElementById("masthead"),
        document.querySelector(".site-header"),
        document.querySelector("header.site-header"),
        document.querySelector(".woocommerce-store-notice"),
      ]

      known.forEach((el) => {
        if (!el) return
        const style = window.getComputedStyle(el)
        if (style.display === "none" || style.visibility === "hidden") return
        if (style.position !== "fixed" && style.position !== "sticky") return
        const rect = el.getBoundingClientRect()
        if (rect.height > 0 && rect.bottom > 0 && rect.top < window.innerHeight * 0.5) {
          fromHeader = Math.max(fromHeader, rect.bottom)
        }
      })

      return Math.max(0, Math.round(Math.max(fromHeader, fromVars)))
    }

    function clearFixed() {
      inner.classList.remove("sledge-combo-summary__inner--floating")
      summary.classList.remove("sledge-combo-summary--floating")
      ;["position", "top", "left", "right", "width", "maxWidth", "maxHeight", "height", "zIndex", "boxSizing"].forEach((prop) => {
        inner.style[prop] = ""
      })
      if (placeholder && placeholder.parentNode) {
        placeholder.parentNode.removeChild(placeholder)
      }
      placeholder = null
      summary.style.minHeight = ""
    }

    function ensurePlaceholder(height) {
      if (!placeholder) {
        placeholder = document.createElement("div")
        placeholder.className = "sledge-combo-summary__placeholder"
        placeholder.setAttribute("aria-hidden", "true")
        summary.insertBefore(placeholder, inner)
      }
      placeholder.style.height = Math.max(0, Math.round(height)) + "px"
      placeholder.style.width = "100%"
      placeholder.style.pointerEvents = "none"
      placeholder.style.visibility = "hidden"
    }

    function apply() {
      scheduled = false

      if (mq.matches) {
        clearFixed()
        return
      }

      const parentRect = summary.getBoundingClientRect()
      const layoutRect = layout.getBoundingClientRect()
      const width = Math.max(0, parentRect.width)

      if (width < 40) {
        clearFixed()
        return
      }

      // Column bounds (aside stretches with the grid row).
      const colTop = parentRect.top
      const colBottom = Math.min(parentRect.bottom, layoutRect.bottom) - bottomPad
      const colHeight = colBottom - colTop

      // Layout fully off-screen — park in normal flow.
      if (layoutRect.bottom < 0 || layoutRect.top > window.innerHeight) {
        clearFixed()
        return
      }

      // Natural content height (ignore current max-height clamp).
      const prevMaxHeight = inner.style.maxHeight
      const prevHeight = inner.style.height
      inner.style.maxHeight = "none"
      inner.style.height = "auto"
      const naturalHeight = Math.max(inner.scrollHeight, inner.offsetHeight)
      inner.style.maxHeight = prevMaxHeight
      inner.style.height = prevHeight

      ensurePlaceholder(naturalHeight)

      const pinTop = pinOffset() + gap
      const viewportRoom = Math.max(120, window.innerHeight - pinTop - gap)
      // Stable panel size: content height, capped by viewport — not by scroll position.
      const panelHeight = Math.min(naturalHeight, viewportRoom, Math.max(120, colHeight))

      // Classic sticky-in-parent:
      // 1) sit at column top until it reaches the pin line
      // 2) pin under header while scrolling
      // 3) stop at column bottom (slide up with the column)
      let top = pinTop
      if (colTop > pinTop) {
        top = colTop
      }
      if (top + panelHeight > colBottom) {
        top = colBottom - panelHeight
      }
      // Never draw above the parent column top.
      if (top < colTop) {
        top = colTop
      }

      const maxHeight = Math.max(0, Math.min(panelHeight, colBottom - top))

      product.style.setProperty("--sledge-combo-sticky-top", Math.round(pinTop) + "px")
      summary.classList.add("sledge-combo-summary--floating")
      inner.classList.add("sledge-combo-summary__inner--floating")

      inner.style.position = "fixed"
      inner.style.top = Math.round(top) + "px"
      inner.style.left = Math.round(parentRect.left) + "px"
      inner.style.right = "auto"
      inner.style.width = Math.round(width) + "px"
      inner.style.maxWidth = Math.round(width) + "px"
      // Lock height so bottom clamp does not shrink the box into a sidebar strip.
      inner.style.height = Math.round(maxHeight) + "px"
      inner.style.maxHeight = Math.round(maxHeight) + "px"
      inner.style.zIndex = "40"
      inner.style.boxSizing = "border-box"

      summary.style.minHeight = Math.round(naturalHeight) + "px"
    }

    function schedule() {
      if (scheduled) return
      scheduled = true
      window.requestAnimationFrame(apply)
    }

    apply()
    window.addEventListener("scroll", schedule, { passive: true })
    window.addEventListener("resize", schedule, { passive: true })
    window.addEventListener("orientationchange", schedule, { passive: true })
    if (typeof ResizeObserver !== "undefined") {
      const ro = new ResizeObserver(schedule)
      ro.observe(summary)
      ro.observe(layout)
    }
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(schedule).catch(() => {})
    }
    window.addEventListener("load", schedule, { passive: true })
    if (typeof mq.addEventListener === "function") {
      mq.addEventListener("change", schedule)
    } else if (typeof mq.addListener === "function") {
      mq.addListener(schedule)
    }
  }

  function updateReservationAmounts() {
    const $reservationContainer = $("#combo-reservation-container")
    if ($reservationContainer.length === 0) return

    const ajaxData = {
      ajax_url: wcComboProduct.reservation_ajax_url || wcComboProduct.ajaxUrl,
      nonce: wcComboProduct.reservation_nonce || wcComboProduct.addComboToCartNonce,
    }

    if (!ajaxData.ajax_url || !ajaxData.nonce) {
      return
    }

    return $.ajax({
      type: "POST",
      url: ajaxData.ajax_url,
      data: {
        action: "update_reservation_amounts",
        nonce: ajaxData.nonce,
      },
      timeout: 10000,
    })
      .done((response) => {
        if (response && response.success && response.data) {
          const data = response.data
          if ($("#reservation-percentage").length) $("#reservation-percentage").text(data.percentage || "")
          if ($("#reservation-deposit").length) $("#reservation-deposit").html(data.deposit || "")
          if ($("#reservation-balance").length) $("#reservation-balance").html(data.balance || "")
          $reservationContainer.show()
        }
      })
      .fail((xhr) => {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
          const errorData = xhr.responseJSON.data
          if (errorData === "Cart total below minimum" || errorData === "No eligible products in cart") {
            $reservationContainer.hide()
          }
        }
      })
  }
})(window.jQuery)
