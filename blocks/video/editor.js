/**
 * "Parish Video" block editor UI: pick one video, then show a server-rendered
 * preview. Written against the wp.* globals so no build step is needed.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var BlockControls = wp.blockEditor.BlockControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var Spinner = wp.components.Spinner;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarButton = wp.components.ToolbarButton;
	var ServerSideRender = wp.serverSideRender;

	function Picker(props) {
		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];
		var resultState = useState({ loading: true, items: [], error: '' });
		var result = resultState[0];
		var setResult = resultState[1];

		useEffect(function () {
			var cancelled = false;
			var timer = setTimeout(function () {
				setResult(function (previous) {
					return { loading: true, items: previous.items, error: '' };
				});
				wp.apiFetch({ path: '/parish-video-center/v1/picker?search=' + encodeURIComponent(search) })
					.then(function (data) {
						if (!cancelled) setResult({ loading: false, items: data.items || [], error: '' });
					})
					.catch(function (error) {
						if (!cancelled) setResult({ loading: false, items: [], error: (error && error.message) || __('Could not load videos.', 'parish-video-center') });
					});
			}, search ? 350 : 0);

			return function () {
				cancelled = true;
				clearTimeout(timer);
			};
		}, [search]);

		var list;
		if (result.error) {
			list = el('p', {}, result.error);
		} else if (!result.loading && !result.items.length) {
			list = el('p', {}, __('No videos found.', 'parish-video-center'));
		} else {
			list = el(
				'ul',
				{ style: { listStyle: 'none', margin: 0, padding: 0, maxHeight: '320px', overflowY: 'auto', width: '100%' } },
				result.items.map(function (item) {
					return el(
						'li',
						{ key: item.postId ? 'p' + item.postId : item.videoId, style: { margin: '0 0 4px' } },
						el(
							'button',
							{
								type: 'button',
								className: 'components-button',
								style: { display: 'flex', gap: '12px', alignItems: 'center', width: '100%', height: 'auto', padding: '6px', textAlign: 'left' },
								onClick: function () { props.onSelect(item); }
							},
							el(
								'span',
								{ style: { flex: '0 0 96px', aspectRatio: '16 / 9', background: '#ddd', borderRadius: '3px', overflow: 'hidden', display: 'block' } },
								item.thumbnail ? el('img', { src: item.thumbnail, alt: '', style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block' } }) : null
							),
							el(
								'span',
								{ style: { display: 'block', whiteSpace: 'normal' } },
								el('strong', { style: { display: 'block' } }, item.title),
								el(
									'span',
									{ style: { color: '#757575', fontSize: '12px' } },
									[item.duration, item.hasPost ? '' : __('Library only', 'parish-video-center')].filter(Boolean).join(' · ')
								)
							)
						)
					);
				})
			);
		}

		return el(
			Placeholder,
			{
				icon: 'video-alt3',
				label: __('Parish Video', 'parish-video-center'),
				instructions: __('Choose the video to show here.', 'parish-video-center')
			},
			el(
				'div',
				{ style: { width: '100%' } },
				el(TextControl, {
					label: __('Search videos', 'parish-video-center'),
					hideLabelFromVision: true,
					placeholder: __('Search videos', 'parish-video-center'),
					value: search,
					onChange: setSearch,
					__nextHasNoMarginBottom: true
				}),
				el('div', { style: { minHeight: '24px', margin: '8px 0 0' } }, result.loading ? el(Spinner) : null),
				list
			)
		);
	}

	wp.blocks.registerBlockType('parish-video-center/video', {
		edit: function (props) {
			var attributes = props.attributes;
			var blockProps = useBlockProps();
			var chosen = !!(attributes.postId || attributes.videoId);

			if (!chosen) {
				return el(
					'div',
					blockProps,
					el(Picker, {
						onSelect: function (item) {
							props.setAttributes({ postId: item.postId || 0, videoId: item.videoId || '' });
						}
					})
				);
			}

			return el(
				'div',
				blockProps,
				el(
					BlockControls,
					{},
					el(
						ToolbarGroup,
						{},
						el(ToolbarButton, {
							onClick: function () { props.setAttributes({ postId: 0, videoId: '' }); }
						}, __('Replace', 'parish-video-center'))
					)
				),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __('Video', 'parish-video-center') },
						el(ToggleControl, {
							label: __('Show the title below the player', 'parish-video-center'),
							checked: attributes.showTitle,
							onChange: function (value) { props.setAttributes({ showTitle: value }); },
							__nextHasNoMarginBottom: true
						})
					)
				),
				// The preview's play button would swap in the live player; keep clicks for selecting the block.
				el('div', { style: { pointerEvents: 'none' } }, el(ServerSideRender, {
					block: 'parish-video-center/video',
					attributes: attributes
				}))
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
