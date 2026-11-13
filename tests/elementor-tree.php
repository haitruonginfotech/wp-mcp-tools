<?php
/**
 * Unit test of the Elementor element-tree engine (no WordPress needed):  php tests/elementor-tree.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-elementor-tree.php';

$fail = 0;
function ok( bool $cond, string $name ): void {
	global $fail;
	if ( $cond ) {
		echo "ok   $name\n";
	} else {
		echo "FAIL $name\n";
		++$fail;
	}
}
function throws( callable $fn, string $name, string $needle = '' ): void {
	try {
		$fn();
		ok( false, $name . ' (no exception)' );
	} catch ( \InvalidArgumentException $e ) {
		ok( '' === $needle || false !== stripos( $e->getMessage(), $needle ), $name . ' [' . $e->getMessage() . ']' );
	}
}

$T = 'WPMCP_Elementor_Tree';

/* ---- classic container page ---- */
$tree = array();
$res  = $T::apply( $tree, array(
	array( 'op' => 'add', 'ref' => 'hero', 'element' => array( 'type' => 'container', 'settings' => array( 'flex_direction' => 'column' ) ) ),
	array( 'op' => 'add', 'parent_id' => '@hero', 'element' => array( 'widget' => 'heading', 'settings' => array( 'title' => 'Hello', 'header_size' => 'h1' ) ) ),
	array( 'op' => 'add', 'parent_id' => '@hero', 'element' => array( 'widget' => 'button', 'settings' => array( 'text' => 'Go' ) ), 'position' => 0 ),
) );
ok( 3 === count( $res ), 'batch returns one row per op' );
ok( 1 === count( $tree ) && 2 === count( $tree[0]['elements'] ), 'container holds two widgets' );
ok( 'button' === $tree[0]['elements'][0]['widgetType'], 'position 0 puts the button first' );
ok( 'Hello' === $tree[0]['elements'][1]['settings']['title'], 'classic widget settings stay plain' );
ok( 7 === strlen( $tree[0]['id'] ), 'ids are 7 chars' );

/* ---- legacy section > column > widget ---- */
$sec = array();
$T::apply( $sec, array( array( 'op' => 'add', 'element' => array(
	'type'     => 'section',
	'children' => array(
		array( 'type' => 'column', 'children' => array( array( 'widget' => 'text-editor', 'settings' => array( 'editor' => '<p>One</p>' ) ) ) ),
		array( 'type' => 'column', 'children' => array( array( 'widget' => 'image', 'settings' => array() ) ) ),
	),
) ) ) );
ok( 50.0 === (float) $sec[0]['elements'][0]['settings']['_column_size'], 'two columns share the row (50)' );
throws( function () use ( $T ) { $t = array(); $T::apply( $t, array( array( 'op' => 'add', 'element' => array( 'widget' => 'heading' ) ) ) ); }, 'widget at root refused', 'root' );
throws( function () use ( $T, $sec ) { $T::apply( $sec, array( array( 'op' => 'add', 'parent_id' => $sec[0]['id'], 'element' => array( 'widget' => 'heading' ) ) ) ); }, 'widget directly in section refused', 'only hold columns' );

/* ---- atomic ---- */
$at = array();
$T::apply( $at, array(
	array( 'op' => 'add', 'ref' => 'box', 'element' => array( 'type' => 'e-flexbox' ) ),
	array( 'op' => 'add', 'parent_id' => '@box', 'element' => array( 'widget' => 'e-heading', 'settings' => array( 'title' => 'Atomic', 'tag' => 'h1', 'link' => 'https://x.test', 'target_blank' => true ) ) ),
	array( 'op' => 'add', 'parent_id' => '@box', 'element' => array( 'widget' => 'e-paragraph', 'settings' => array( 'content' => 'Body' ) ) ),
	array( 'op' => 'add', 'parent_id' => '@box', 'element' => array( 'widget' => 'e-image', 'settings' => array( 'image_url' => 'https://x.test/a.jpg', 'alt' => 'A' ) ) ),
	array( 'op' => 'add', 'parent_id' => '@box', 'element' => array( 'widget' => 'e-button', 'settings' => array( 'text' => 'Buy', 'css_id' => 'buy' ) ) ),
) );
$h = $at[0]['elements'][0]['settings'];
ok( 'html-v3' === $h['title']['$$type'] && 'Atomic' === $h['title']['value']['content']['value'], 'e-heading title becomes html-v3' );
ok( 'h1' === $h['tag']['value'], 'e-heading tag typed' );
ok( 'link' === $h['link']['$$type'] && true === $h['link']['value']['isTargetBlank']['value'], 'link with target blank' );
ok( 'classes' === $h['classes']['$$type'], 'classes default added' );
ok( 'Body' === $at[0]['elements'][1]['settings']['paragraph']['value']['content']['value'], 'e-paragraph content -> paragraph prop' );
ok( 'image' === $at[0]['elements'][2]['settings']['image']['$$type'], 'e-image prop built' );
ok( 'buy' === $at[0]['elements'][3]['settings']['_cssid']['value'], 'css_id -> _cssid' );
ok( 'Atomic' === $T::outline( $at )[0]['children'][0]['text'], 'outline previews typed text' );

/* typed values pass through; update with plain value wraps; null removes */
$id = $at[0]['elements'][0]['id'];
$T::apply( $at, array( array( 'op' => 'update', 'element_id' => $id, 'settings' => array( 'title' => 'New', 'tag' => null ) ) ) );
$h = $at[0]['elements'][0]['settings'];
ok( 'New' === $h['title']['value']['content']['value'], 'update wraps plain title' );
ok( ! isset( $h['tag'] ), 'update with null removes a key' );
ok( isset( $h['link'] ), 'update keeps untouched keys' );
$typed = array( '$$type' => 'string', 'value' => 'h3' );
$T::apply( $at, array( array( 'op' => 'update', 'element_id' => $id, 'settings' => array( 'tag' => $typed ) ) ) );
ok( 'h3' === $at[0]['elements'][0]['settings']['tag']['value'], 'typed value passes through' );

/* ---- move / duplicate / remove ---- */
$ids = array( $at[0]['elements'][0]['id'], $at[0]['elements'][1]['id'] );
$T::apply( $at, array( array( 'op' => 'move', 'element_id' => $ids[0], 'position' => 2 ) ) );
ok( $ids[0] === $at[0]['elements'][2]['id'], 'move within parent' );
$T::apply( $at, array( array( 'op' => 'duplicate', 'element_id' => '' . $at[0]['id'] ) ) );
ok( 2 === count( $at ) && $at[0]['id'] !== $at[1]['id'], 'duplicate root container right after original' );
ok( $at[0]['elements'][0]['id'] !== $at[1]['elements'][0]['id'], 'duplicate gets new child ids' );
$before = json_encode( $at );
throws( function () use ( $T, &$at ) { $T::apply( $at, array( array( 'op' => 'move', 'element_id' => $at[0]['id'], 'parent_id' => $at[0]['elements'][0]['id'] ) ) ); }, 'move into own child refused' );
ok( $before === json_encode( $at ), 'refused move leaves the tree unchanged' );
$n = $T::size( $at );
$T::apply( $at, array( array( 'op' => 'remove', 'element_id' => $at[1]['id'] ) ) );
ok( $T::size( $at ) === $n - 5, 'remove deletes element and children' );
throws( function () use ( $T, &$at ) { $T::apply( $at, array( array( 'op' => 'remove', 'element_id' => 'nope123' ) ) ); }, 'remove unknown id', 'not found' );

/* move across parents, legacy */
$m = array();
$T::apply( $m, array(
	array( 'op' => 'add', 'ref' => 'a', 'element' => array( 'type' => 'container' ) ),
	array( 'op' => 'add', 'ref' => 'b', 'element' => array( 'type' => 'container' ) ),
	array( 'op' => 'add', 'ref' => 'w', 'parent_id' => '@a', 'element' => array( 'widget' => 'heading', 'settings' => array( 'title' => 'X' ) ) ),
	array( 'op' => 'move', 'element_id' => '@w', 'parent_id' => '@b' ),
) );
ok( 0 === count( $m[0]['elements'] ) && 1 === count( $m[1]['elements'] ), 'move between containers' );

/* ---- find / replace ---- */
$f = $T::find( $m, array( 'widget_type' => 'heading' ) );
ok( 1 === count( $f ) && 'X' === $f[0]['text'], 'find by widget type' );
$f = $T::find( $at, array( 'text' => 'buy' ) );
ok( count( $f ) >= 1, 'find by text (case-insensitive)' );
$rep = array();
$T::apply( $rep, array(
	array( 'op' => 'add', 'element' => array( 'type' => 'container', 'children' => array(
		array( 'widget' => 'heading', 'settings' => array( 'title' => 'Old name here' ) ),
		array( 'widget' => 'e-paragraph', 'settings' => array( 'content' => 'Old text, old link', 'link' => 'https://old.test/a' ) ),
	) ) ),
) );
$r = $T::apply( $rep, array( array( 'op' => 'replace', 'search' => 'Old', 'replace' => 'New' ) ) );
ok( 2 === $r[0]['replacements'], 'replace counts strings changed (typed values too)' );
ok( 'New name here' === $rep[0]['elements'][0]['settings']['title'], 'replace in classic setting' );
$r = $T::apply( $rep, array( array( 'op' => 'replace', 'search' => '#old\.test#', 'replace' => 'new.test', 'regex' => true ) ) );
ok( 'https://new.test/a' === $rep[0]['elements'][1]['settings']['link']['value']['destination']['value'], 'regex replace reaches urls' );
throws( function () use ( $T, &$rep ) { $T::apply( $rep, array( array( 'op' => 'replace', 'search' => '/(/', 'replace' => 'x', 'regex' => true ) ) ); }, 'bad regex refused', 'regular expression' );

/* ---- errors ---- */
throws( function () use ( $T ) { $t = array(); $T::apply( $t, array( array( 'op' => 'fly' ) ) ); }, 'unknown op', 'unknown operation' );
throws( function () use ( $T ) { $t = array(); $T::apply( $t, array( array( 'op' => 'add', 'element' => array( 'settings' => array() ) ) ) ); }, 'element without type', 'needs' );
throws( function () use ( $T ) { $t = array(); $T::apply( $t, array( array( 'op' => 'add', 'parent_id' => '@zz', 'element' => array( 'type' => 'container' ) ) ) ); }, 'unknown @ref', 'unknown reference' );
throws( function () use ( $T ) { $t = array(); $T::apply( $t, array( array( 'op' => 'add', 'element' => array( 'type' => 'container', 'children' => array( array( 'widget' => 'heading', 'children' => array( array( 'widget' => 'x' ) ) ) ) ) ) ) ); }, 'widget with children refused', 'cannot have children' );

/* full Elementor JSON is accepted as is, ids kept when free */
$full = array();
$T::apply( $full, array( array( 'op' => 'add', 'element' => array( 'id' => 'abc1234', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'T' ), 'elements' => array() ) ) ) ) ) );
ok( 'abc1234' === $full[0]['id'] && 'abc1234' !== $full[0]['elements'][0]['id'], 'full JSON: id kept once, duplicates re-issued' );

/* duplicate to root, add with parent "root" */
$d = array();
$T::apply( $d, array(
	array( 'op' => 'add', 'ref' => 'a', 'element' => array( 'type' => 'container' ) ),
	array( 'op' => 'add', 'ref' => 'w', 'parent_id' => '@a', 'element' => array( 'widget' => 'heading', 'settings' => array( 'title' => 'X' ) ) ),
	array( 'op' => 'duplicate', 'element_id' => '@a', 'parent_id' => 'root' ),
	array( 'op' => 'add', 'parent_id' => 'root', 'element' => array( 'type' => 'e-div-block' ) ),
) );
ok( 3 === count( $d ), 'duplicate/add with parent root land at the page root' );

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
