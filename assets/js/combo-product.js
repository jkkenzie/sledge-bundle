;(($) => {
  // Ensure the jQuery UI accordion and sortable functions are loaded
  if (typeof $.fn.accordion !== "undefined" && typeof $.fn.sortable !== "undefined") {
    // Initialize when document is ready
    $(document).ready(() => {
      initializeComboFields()
    })

    function initializeComboFields() {
      // Show/hide combo fields based on product type
      function showComboFields() {
        var productType = $("#product-type").val()
        if (productType === "combo") {
          $(".show_if_combo").show()
        } else {
          $(".show_if_combo").hide()
        }
      }

      // Initial setup
      $("#product-type").change(showComboFields)
      showComboFields()

      // Initialize accordion for combo products with proper height handling
      $("#combo_product_fields")
        .accordion({
          header: "h3",
          collapsible: true,
          active: false,
          heightStyle: "content",
          activate: (event, ui) => {
            // Fix height issues when accordion opens
            if (ui.newPanel.length) {
              ui.newPanel.find(".combo-product-content").show()
              ui.newPanel.addClass("ui-accordion-content-active")
            }
            if (ui.oldPanel.length) {
              ui.oldPanel.find(".combo-product-content").hide()
              ui.oldPanel.removeClass("ui-accordion-content-active")
            }
          },
        })
        .sortable()

      // Initialize Select2 if available
      if (typeof $.fn.select2 !== "undefined") {
        $("#add_combo_product_select").select2({
          placeholder: "Select a Product",
          allowClear: true,
          width: "300px",
        })

        $("#combo_product_category").select2({
          placeholder: "Select a Category",
          allowClear: true,
          width: "300px",
        })

        $("#combo_category_product_select").select2({
          placeholder: "Select a Product",
          allowClear: true,
          width: "300px",
        })
      }

      // Initialize tabs
      initializeTabs()
    }

    // Initialize WordPress-style tabs
    function initializeTabs() {
      $(".nav-tab").on("click", function (e) {
        e.preventDefault()

        // Remove active class from all tabs and panes
        $(".nav-tab").removeClass("nav-tab-active")
        $(".tab-pane").removeClass("active")

        // Add active class to clicked tab
        $(this).addClass("nav-tab-active")

        // Show corresponding tab pane
        const target = $(this).attr("href")
        $(target).addClass("active")
      })
    }

    // Expand all accordion items
    $(document).on("click", "#expand_all_combo_items", (e) => {
      e.preventDefault()
      $("#combo_product_fields .combo_product_field").each((index) => {
        $("#combo_product_fields").accordion("option", "active", index)
      })
      // Set to expand all
      $("#combo_product_fields").accordion("option", "active", false)
      $("#combo_product_fields .combo_product_field").each(function () {
        $(this).find(".combo-product-content").show()
        $(this).addClass("ui-accordion-content-active")
      })
    })

    // Collapse all accordion items
    $(document).on("click", "#collapse_all_combo_items", (e) => {
      e.preventDefault()
      $("#combo_product_fields").accordion("option", "active", false)
      $("#combo_product_fields .combo_product_field").each(function () {
        $(this).find(".combo-product-content").hide()
        $(this).removeClass("ui-accordion-content-active")
      })
    })

    // Handle category selection change
    $(document).on("change", "#combo_product_category", function () {
      const categoryId = $(this).val()
      const $productSelect = $("#combo_category_product_select")
      const $addButton = $("#add_combo_category_product_button")

      if (!categoryId) {
        $productSelect.prop("disabled", true).html('<option value="">Select a category first</option>')
        $addButton.prop("disabled", true)

        // Reinitialize Select2 if available
        if (typeof $.fn.select2 !== "undefined") {
          $productSelect.select2("destroy").select2({
            placeholder: "Select a category first",
            allowClear: true,
            width: "300px",
          })
        }
        return
      }

      // Show loading state
      $productSelect.prop("disabled", true).html('<option value="">Loading products...</option>')
      $addButton.prop("disabled", true)

      // AJAX call to get products by category
      $.ajax({
        url: window.ajaxurl || "/wp-admin/admin-ajax.php",
        type: "POST",
        data: {
          action: "get_products_by_category",
          category_id: categoryId,
          nonce: window.wc_combo_admin ? window.wc_combo_admin.nonce : "",
        },
        success: (response) => {
          if (response.success && response.data) {
            let options = '<option value="">Select a product</option>'
            $.each(response.data, (id, name) => {
              options += '<option value="' + id + '">' + name + "</option>"
            })
            $productSelect.prop("disabled", false).html(options)
            $addButton.prop("disabled", false)

            // Reinitialize Select2 if available
            if (typeof $.fn.select2 !== "undefined") {
              $productSelect.select2("destroy").select2({
                placeholder: "Select a Product",
                allowClear: true,
                width: "300px",
              })
            }
          } else {
            $productSelect.prop("disabled", true).html('<option value="">No products found</option>')
            $addButton.prop("disabled", true)

            // Reinitialize Select2 if available
            if (typeof $.fn.select2 !== "undefined") {
              $productSelect.select2("destroy").select2({
                placeholder: "No products found",
                allowClear: true,
                width: "300px",
              })
            }
          }
        },
        error: (xhr, status, error) => {
          console.error("AJAX Error:", error)
          $productSelect.prop("disabled", true).html('<option value="">Error loading products</option>')
          $addButton.prop("disabled", true)

          // Reinitialize Select2 if available
          if (typeof $.fn.select2 !== "undefined") {
            $productSelect.select2("destroy").select2({
              placeholder: "Error loading products",
              allowClear: true,
              width: "300px",
            })
          }
        },
      })
    })

    // Add combo product field (from name tab)
    $(document).on("click", "#add_combo_product_button", (e) => {
      e.preventDefault()
      addComboProduct("#add_combo_product_select")
    })

    // Add combo product field (from category tab)
    $(document).on("click", "#add_combo_category_product_button", (e) => {
      e.preventDefault()
      addComboProduct("#combo_category_product_select")
    })

    // Common function to add combo product
    function addComboProduct(selectId) {
      const index = $("#combo_product_fields .combo_product_field").length
      const product_id = $(selectId).val()
      const product_title = $(selectId + " option:selected").text()

      if (!product_id) {
        alert("Please select a product")
        return
      }

      // Check if product is already added
      let productExists = false
      $("#combo_product_fields input[name*='[product_id]']").each(function () {
        if ($(this).val() == product_id) {
          productExists = true
          return false
        }
      })

      if (productExists) {
        alert("This product is already added to the combo")
        return
      }

      const template = document.getElementById("new-product-template").innerHTML

      var html = template.replace(/\${([^}]*)}/g, (match, placeholder) => {
        switch (placeholder) {
          case "index":
            return index
          case "product_id":
            return product_id
          case "product_title":
            return product_title
          default:
            return ""
        }
      })

      $("#combo_product_fields").append(html).accordion("refresh")

      // Clear the select
      $(selectId).val("").trigger("change")

      // Update indices
      updateIndices()
    }

    // Remove combo product field
    $(document).on("click", ".remove_combo_product", function (e) {
      e.preventDefault()

      if (confirm("Are you sure you want to remove this product from the combo?")) {
        $(this).closest(".combo_product_field").remove()
        $("#combo_product_fields").accordion("refresh")

        // Update indices
        updateIndices()

        // Show "no products" message if no products left
        if ($("#combo_product_fields .combo_product_field").length === 0) {
          $("#combo_product_fields").html(
            '<p class="no-combo-products">No products added to this combo yet. Use the form below to add products.</p>',
          )
        }
      }
    })

    // Update indices after reordering
    function updateIndices() {
      $("#combo_product_fields .combo_product_field").each(function (index) {
        $(this).attr("data-index", index)

        // Update all input names and IDs
        $(this)
          .find("input, select, textarea")
          .each(function () {
            const name = $(this).attr("name")
            const id = $(this).attr("id")

            if (name) {
              const newName = name.replace(/\[\d+\]/, "[" + index + "]")
              $(this).attr("name", newName)
            }

            if (id) {
              const newId = id.replace(/_\d+$/, "_" + index)
              $(this).attr("id", newId)
            }
          })

        // Update labels
        $(this)
          .find("label")
          .each(function () {
            const forAttr = $(this).attr("for")
            if (forAttr) {
              const newFor = forAttr.replace(/_\d+$/, "_" + index)
              $(this).attr("for", newFor)
            }
          })
      })
    }

    // Save button functionality
    $(document).on("click", ".save-product-bundles", (e) => {
      e.preventDefault()
      $("#publish").click()
    })
  } else {
    console.error("jQuery UI accordion or sortable is not loaded")
  }
})(jQuery)
