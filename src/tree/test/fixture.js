/**
 * Core's list table as the Tree view prints it, for the tree script's tests.
 */

/**
 * One row as core prints it, with the Hierarchy cell the plugin prints.
 *
 * @param {Object}  row          Row.
 * @param {number}  row.id       Post ID.
 * @param {string}  row.title    Title.
 * @param {number}  row.level    Level, from 0.
 * @param {number}  row.parent   Parent ID.
 * @param {number}  row.children Live subpages.
 * @param {number}  row.position Place among its siblings.
 * @param {number}  row.setsize  Number of its siblings.
 * @param {boolean} row.toggle   Whether the user may open it.
 * @param {number}  row.open     The children page the server opened, or 0.
 * @param {string}  row.extra    More cell markup.
 * @return {string} Markup.
 */
export function coreRow( {
	id,
	title,
	level = 0,
	parent = 0,
	children = 0,
	position = 1,
	setsize = 3,
	toggle = children > 0,
	open = 0,
	extra = '',
} ) {
	const sep = '<span aria-hidden="true"> · </span>';
	const toggleLink = toggle
		? `<a class="hierarchy-guard-toggle" href="edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=${ id }"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text">Show the subpages of “${ title }”</span></a>${ sep }`
		: '';
	const count = children
		? `<a href="edit.php?post_type=page&hierarchy_guard_under=${ id }">${ children } subpages</a>`
		: '<span aria-hidden="true">—</span>';
	return `<tr id="post-${ id }" class="iedit level-${ level }">
		<th scope="row" class="title column-title has-row-actions column-primary page-title" data-colname="Title" aria-label="${ title }"><div class="locked-info"><span class="locked-avatar"></span> <span class="locked-text"></span></div><strong><a class="row-title" href="post.php?post=${ id }&action=edit">${ title }</a></strong><div class="row-actions"><span class="trash"><a href="post.php?post=${ id }&action=trash" class="submitdelete">Trash</a></span></div></th>
		<td class="hierarchy_guard column-hierarchy_guard" data-colname="Hierarchy"><span class="hierarchy-guard-cell" data-id="${ id }" data-children="${ children }" data-status="publish" data-level="${ level }" data-parent="${ parent }" data-page="1" data-position="${ position }" data-setsize="${ setsize }"${ open ? ` data-open="${ open }"` : '' }>${ toggleLink }${ count }${ extra }</span></td>
		<td class="author column-author" data-colname="Author">Sam</td>
		<td class="comments column-comments hidden" data-colname="Comments"></td>
		<td class="date column-date" data-colname="Date">Published<br>2026/09/02 at 3:04 pm</td>
	</tr>`;
}

/**
 * The screen: the tree's data, Collapse all and the table.
 *
 * @param {string} rows Row markup.
 * @return {void}
 */
export function mountScreen( rows ) {
	document.body.innerHTML = `<div class="wrap">
		<span class="hierarchy-guard-tree" hidden data-type="page" data-place="https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_view=tree&paged=2" data-total="3" data-start="20" data-per-page="50"></span>
		<div class="alignleft actions"><a class="hierarchy-guard-collapse" href="edit.php?post_type=page&hierarchy_guard_view=tree">Collapse all</a></div>
		<table class="wp-list-table widefat fixed striped pages"><thead><tr><th id="title">Title</th><th id="hierarchy_guard">Hierarchy</th><th id="author">Author</th><th id="comments" class="hidden">Comments</th><th id="date">Date</th></tr></thead>
		<tbody id="the-list">${ rows }</tbody></table></div>`;
}

/**
 * One subpage as the hierarchy route sends it.
 *
 * @param {number} id        Post ID.
 * @param {string} title     Title.
 * @param {Object} overrides Fields to change.
 * @return {Object} Child.
 */
export function child( id, title, overrides = {} ) {
	return {
		id,
		title,
		status: 'publish',
		status_label: 'Published',
		children_count: 0,
		children_capped: false,
		author: 'Priya',
		date: { label: 'Published', date: '2026/07/19 at 9:00 am' },
		edit_link: `https://example.org/wp-admin/post.php?post=${ id }&action=edit`,
		view_link: `https://example.org/page-${ id }/`,
		trash_link: `https://example.org/wp-admin/post.php?post=${ id }&action=trash&_wpnonce=abc`,
		...overrides,
	};
}

/**
 * A page of subpages as the hierarchy route sends it.
 *
 * @param {Object[]} children Children.
 * @param {number}   total    Children in all.
 * @param {boolean}  hasMore  Whether a next page exists.
 * @param {boolean}  capped   Whether more than the cap exist.
 * @return {Object} Hierarchy.
 */
export function page( children, total, hasMore = false, capped = false ) {
	return {
		children,
		children_total: total,
		children_capped: capped,
		has_more: hasMore,
	};
}
