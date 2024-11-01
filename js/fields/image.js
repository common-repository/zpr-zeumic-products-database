var jsgrid_field_name;

(function() {
	if (typeof zpr === 'undefined') {
		return;
	}
	var $ = jQuery;
	
	var ImageField = function(config) {
		jsGrid.Field.call(this, config);
	};
	 
	ImageField.prototype = new jsGrid.Field({
		inserting: true,
		editing: true,
		filtering: false,
		sorting: false,
		multiple: false,
		
		align: "left",
		
		// Opens the WP Media Uploader to upload images. Returns the selected image data as JSON
		uploadImages: function(onSelectImages) {
			var img = wp.media({
				title: 'Upload Image',
				multiple: this.multiple
			}).open()
			.on('select', function(e) {
				// This will return the selected image from the Media Uploader, the result is an object
				var selected_imgs = img.state().get('selection');
				// Call onSelectedImages() with the selected image data as JSON
				onSelectImages(selected_imgs.toJSON());
			});
		},
		
		/**
		 * Bind an image mouseover popup to an element, so that when the element is moused over an image popup will display with the given preview_url.
		 */
		bindPopup: function($el, preview_url) {
			if ($el.data('preview_url')) {
				// If bindPopup has already been called, don't redo it, just change the preview URL
				$el.data('preview_url', preview_url);
				return;
			}

			$el.data('preview_url', preview_url);
			$el.mouseover(function(e) {
				this._popup = $('<div class="z-image-popup"><img src="' + $(e.target).data('preview_url') + '" /></div>').appendTo('body');
				this._popup.css({
					'position': 'fixed',
					'top': '20%',
					'left': e.pageX + 100,
					'z-index': 100,
					'max-width': 600,
				});
			});
			$el.mouseout(function(e) {
				$('div.z-image-popup').remove();
			});
		},
	 
		itemTemplate: function(value, item) {
			this._display = $('<span></span>');
			if (!value)
				return this._display;
			
			var imgs = [];
			if (this.multiple) {
				imgs = value;
			} else {
				imgs = [value];
			}
			var _this = this;
			
			var $container = $('<span class="z-image-group"></span>');
			this._display.append($container);
			for (var i = 0; i < imgs.length; i++) {
				var id = imgs[i];
				if (zpr.image(id) === undefined) {
					continue;
				}

				var $thumb = $('<span name="' + id + '" class="z-image"><a class="z-image-thumbnail" title="Click to view fullsize" href="' + zpr.image(id).full + '" target="_blank"><img name="' + id + '" src="' + zpr.image(id).thumbnail + '" /></a> </span>');
				// Don't start editing when the image is clicked
				$thumb.on('click', function(e) {
					e.stopPropagation();
				});

				(function($thumb, id) {
					zpr.meta.onUpdate('images', function() {
						if (!zpr.image(id)) {
							return;
						}
						$thumb.children('a').attr('href', zpr.image(id).full);
						// When moused over it should have a popup
						_this.bindPopup($thumb.find('img'), zpr.image(id).preview);
					}, true);
				})($thumb, id);

				$container.append($thumb);
			}
			return this._display;
		},
		
		insertTemplate: function() {
			return this.editTemplate();
		},
		
		insertValue: function() {
			return this.editValue();
		},
		
		editTemplate: function(value, item) {
			this.editControl = $('<span></span>');
			var _this = this; // Otherwise "this" is too hard to reference
			
			if (this.multiple) {
				var imgs = value || [];
				this._numImgs = Math.max(this._numImgs || 0, imgs.length); // For editValue() to use
				
				var $table = $('<table cellpadding="0" cellspacing="0" border="0" class="z-image-table"></table>');
				var $tbody = $('<tbody class="z-image-group"></tbody>');
				$table.append($tbody);
				this.editControl.append($table);
				for (var i = 0; i < imgs.length; i++) {
					var id = imgs[i];
					if (zpr.image(id) === undefined) {
						continue;
					}
					var $tr = $('<tr name="' + id + '" class="z-image"></tr>');
					var $thumb = $('<td class="z-image-thumbnail"><a title="Drag to reorder" href="' + zpr.image(id).full + '" target="_blank"><img src="' + zpr.image(id).thumbnail + '" /></a></td>');
					// Whenever the server sends new image details, update them here

					(function($thumb, id) {
						zpr.meta.onUpdate('images', function() {
							if (!zpr.image(id)) {
								return;
							}
							$thumb.children('a').attr('href', zpr.image(id).full);
							// When moused over it should have a popup
							_this.bindPopup($thumb.find('img'), zpr.image(id).preview);
						}, true);
					})($thumb, id);
					
					$tr.append($thumb);
					$tbody.append($tr);
				}
			} else {
				var id = value || 0;
				var $thumb = $('<span name="' + id + '" class="z-image"></span>');
				if (zpr.image(id) !== undefined) {
					$thumb.append('<span class="z-image-thumbnail"><a href="' + zpr.image(id).full + '" target="_blank"><img src="' + zpr.image(id).thumbnail + '" /></a>');

					zpr.meta.onUpdate('images', function() {
						if (!zpr.image(id)) {
							return;
						}
						$thumb.find('a').attr('href', zpr.image(id).full);
						// When moused over it should have a popup
						_this.bindPopup($thumb.find('img'), zpr.image(id).preview);
					}, true);
				}
				this.editControl.append($thumb);
			}
			
			var onClickEdit = function(e) {
				e.preventDefault();
				
				// Open WP Media Uploader
				_this.uploadImages(function(selected_imgs) {
					var selected = [];
					for (var i = 0; i < selected_imgs.length; i++) {
						var id = selected_imgs[i].id;
						selected.push(id);
						if (zpr.image(id) === undefined) {
							zpr.updateImage(id, {
								'thumbnail': selected_imgs[i].url,
								'preview': selected_imgs[i].url,
								'full': selected_imgs[i].url,
							});
						}
					}
					// Update edit field with the new images
					_this.editControl.empty();
					_this.editControl.append(_this.editTemplate(selected, item));
				});
			}
			
			if (!this._editThickbox) {
				this._editThickbox = $('<div id="zpr-' + this.name + '-thickbox" style="display:none;"></div>');
				$('body').append(this._editThickbox);
			}
			
			if (this.multiple) {
				// The images should be draggable and droppable on each other to move them around
				this.editControl.find('.z-image-group').sortable({
					tolerance: 'pointer',
					placeholder: 'z-image-thumbnail-placeholder',
					forcePlaceholderSize: true,
				});
			}
			
			// Add edit and delete buttons
			
			var editButton = $('<input class="jsgrid-button jsgrid-edit-button" type="button" title="Edit" />');
			this.editControl.append(editButton);
			
			var deleteButton = $('<input class="jsgrid-button jsgrid-delete-button" type="button" title="Remove' + (this.multiple ? ' All' : '') + '" />');
			this.editControl.append(deleteButton);
			
			editButton.on('click', onClickEdit);
			
			deleteButton.on('click', function(e) {
				// Update edit field, removing all images
				_this.editControl.empty();
				_this.editControl.append(_this.editTemplate(null, item));
			});
			
			return this.editControl;
		},
		
		editValue: function() {
			var imgs = [];
			this.editControl.find('.z-image').each(function(index) {
				imgs.push(Number($(this).attr('name')));
			});
			if (!this.multiple) {
				if (imgs.length === 0) {
					return 0;
				} else {
					return imgs[0];
				}
			} else {
				// Pad it out to get around weird jsGrid quirk
				while (imgs.length < this._numImgs) {
					imgs.push(0);
				}
				return imgs;
			}
		},
	});

	jsGrid.fields[jsgrid_field_name] = ImageField;
})();
