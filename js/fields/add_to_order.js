var jsgrid_field_name;

(function() {
	if (typeof zpr === 'undefined') {
		return;
	}
	var $ = jQuery;

	var AddToOrderField = function(config) {
		jsGrid.Field.call(this, config);
	};
	AddToOrderField.prototype = new jsGrid.Field({
		sorting: false,
		
		align: "left",
	 
		itemTemplate: function(value, item) {
			var _this = this;
			
			var display = $('<span><button>Add</button><br/><a href="#" target="_blank">View order</a></span>');
			var viewOrderLink = display.children('a');
			viewOrderLink.hide();
			display.children('button').click(function(e) {
				var btn = $(this);
				btn.html("Adding ...");
				btn.prop('disabled', true);
				var order_id = Number(_this.filterControl.children("select").val());
				var discount = Number(_this.filterControl.children("input").val());
				zsc.ajax({
					data: {
						action: 'zpr_add_to_order',
						product_id: item.id,
						order_id: order_id,
						discount: discount,
					},
					success: function (resp, ts, xhr) {
						btn.html("Added!");
						btn.prop('disabled', false);
						viewOrderLink.attr('href', zsc.settings.adminUrl + "post.php?post=" + order_id + "&action=edit");
						viewOrderLink.show();
						// Reload ZWM if present
						if (typeof zwm !== 'undefined') {
							zwm.grid.loadData();
						}
					},
					error: function(xhr, textStatus, errorThrown) {
						btn.html("Retry");
						btn.prop('disabled', false);
					}
				});
				e.stopPropagation();
			});
			return display;
		},

		/**
		 * Template for a select element with all orders as options, and optional discount field.
		 */
		controlTemplate: function(include_dummy) {
			var $select = $('<select></select>');
			var $control = $('<span></span>').append($select).append($('<input type="number" placeholder="Discount (%)" />'));

			// Update the Add to Order column's filter if orders change on the server.
			zpr.meta.onUpdate('orders', function(new_orders) {
				$select.empty();
				if (include_dummy) {
					$select.append($('<option value="0"></option>'));
				}
				for (var i in new_orders) {
					var order = new_orders[i];
					$select.append('<option value="' + order.id + '">' + order.client + ' ' + order.id + '</option>');
				}
			}, true);

			return $control;
		},

		controlValue: function($control) {
			return {
				order_id: $control.find('select').val(),
				discount: $control.find('input').val(),
			};
		},

		editTemplate: function() {
			return this.editControl = this.controlTemplate(true);
		},

		editValue: function() {
			return this.controlValue(this.editControl);
		},

		filterTemplate: function() {
			return this.filterControl = this.controlTemplate();
		},

		filterValue: function() {
			return; // Filter value is unneeded for now, no need to put it in AJAX request
		},

		insertTemplate: function() {
			return this.insertControl = this.controlTemplate(true);
		},

		insertValue: function() {
			return this.controlValue(this.insertControl);
		},
	});
	jsGrid.fields[jsgrid_field_name] = AddToOrderField;
})();
