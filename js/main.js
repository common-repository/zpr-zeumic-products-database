var ZPR;
(function() {
	var $ = jQuery;

	ZPR = function(settings) {
		// Set default settings
		settings = $.extend({
			addToOrder: false,
			autoExpand: false,
		}, settings || {});

		zsc.Plugin.call(this, settings);

		if (this.settings.addToOrder) {
			// If adding to order is enabled, start requesting new list of orders from the server each request
			// (new list will be sent if number of orders has changed)
			this.meta.register({
				orders: {
					updateQuery: function(orders) {
						if (!orders) {
							return 0;
						}
						return orders.length;
					},
				},
			});
		}
	}

	ZPR.prototype = Object.create(zsc.Plugin.prototype);
	$.extend(ZPR.prototype, {
		/* Begin overrides */

		init: function(args) {
			var _ = this;
			zsc.Plugin.prototype.init.call(_, args);

			// Make textareas automatically widen when focused
			if (_.settings.autoExpand) {
				_.onCreate("td.jsgrid-cell textarea", function($el) {
					$el.on('focusin', function() {
						$cell = $(this).parents('td.jsgrid-cell');
						var index = $cell.index() + 1;
						var $jsgrid = $(this).parents(".jsgrid");
						
						// Delay so that if focusin and focusout happen at the same time they will resize at the same time too
						setTimeout(function() {
							$cell.addClass('expanded-input');
							$jsgrid.find('th.jsgrid-header-cell:nth-child(' + index + ')').addClass('expanded-col');
							$jsgrid.find('td.jsgrid-cell:nth-child(' + index + ')').addClass('expanded-col');
						}, 100);
					});
					$el.on('focusout', function() {
						$cell = $(this).parents('td.jsgrid-cell');
						var index = $(this).parents('td.jsgrid-cell').index() + 1;
						var $jsgrid = $(this).parents(".jsgrid");
						
						// Delay so that it won't interfere with click events
						setTimeout(function() {
							$cell.removeClass('expanded-input');
							$jsgrid.find('th.jsgrid-header-cell:nth-child(' + index + ')').removeClass('expanded-col');
							$jsgrid.find('td.jsgrid-cell:nth-child(' + index + ')').removeClass('expanded-col');
						}, 100);
					});
				});
			}
		},

		onDataLoaded: function(args) {
			zsc.Plugin.prototype.onDataLoaded.call(this, args);

			var tvr = this.meta.get('total_value_regular');
			var tvs = this.meta.get('total_value_sale');
			if (tvr !== undefined && tvs !== undefined) {
				$('#zpr_total_value').html("<b>Total value of products in search (stock * price):</b> Regular $" + tvr + ", with sales $" + tvs);
			}
		},

		/* End overrides */

		image: function(id) {
			var images = this.meta.get('images');
			if (!images) {
				return undefined;
			}
			return images[id];
		},


		/**
		 * Reload the list of categories from the server and update the categories field accordingly.
		 */
		loadCategories: function() {
			var _ = this;
			return _.ajax({
				data: {
					action: _.ticker + '_load_categories',
				},
				success: function(res) {
					if (_.getField('categories')) {
						_.getField('categories').updateItems(res.categories); // TODO: fix
					}
				},
				loadData: false,
			});
		},

		updateImage: function(id, newImg) {
			var imgs = this.meta.get('images') || [];
			imgs[id] = newImg;
			this.meta.set('images', imgs); // This will also call all onUpdate handlers
		},
	});
})();