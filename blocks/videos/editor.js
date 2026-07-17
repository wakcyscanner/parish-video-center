/**
 * "Parish Videos" block editor UI: inspector controls + server-rendered
 * preview. Written against the wp.* globals so no build step is needed.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType('parish-video-center/videos', {
		edit: function (props) {
			var attributes = props.attributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __('Videos', 'parish-video-center') },
						el(TextControl, {
							label: __('Heading', 'parish-video-center'),
							help: __('Optional heading shown above the videos.', 'parish-video-center'),
							value: attributes.title,
							onChange: function (value) { props.setAttributes({ title: value }); }
						}),
						el(RangeControl, {
							label: __('Number of videos', 'parish-video-center'),
							min: 1,
							max: 24,
							value: attributes.count,
							onChange: function (value) { props.setAttributes({ count: value }); }
						}),
						el(SelectControl, {
							label: __('Layout', 'parish-video-center'),
							value: attributes.layout,
							options: [
								{ label: __('Grid', 'parish-video-center'), value: 'grid' },
								{ label: __('Slider', 'parish-video-center'), value: 'slider' }
							],
							onChange: function (value) { props.setAttributes({ layout: value }); }
						})
					)
				),
				el(ServerSideRender, {
					block: 'parish-video-center/videos',
					attributes: attributes
				})
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
