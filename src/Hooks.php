<?php

namespace MediaWiki\Extension\DISPLAYCAT;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\DisplayTitle\DisplayTitleService;
use MediaWiki\Hook\CategoryViewer__generateLinkHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Json\JsonCodec;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageProps;
use MediaWiki\Page\PageStore;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\Sanitizer;

class Hooks implements ParserFirstCallInitHook, CategoryViewer__generateLinkHook {
	private const BAD_TAGS = [
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'div',
		'blockquote',
		'ol',
		'ul',
		'li',
		'hr',
		'table',
		'tr',
		'th',
		'td',
		'dl',
		'dd',
		'caption',
		'p',
		'ruby',
		'rb',
		'rt',
		'rtc',
		'rp',
		'br'
	];

	private JsonCodec $jsonCodec;
	private LinkRenderer $linkRenderer;
	private PageStore $pageStore;
	private PageProps $pageProps;
	private DisplayTitleService $displayTitleService;

	public function __construct(
		JsonCodec $jsonCodec,
		LinkRenderer $linkRenderer,
		PageStore $pageStore,
		PageProps $pageProps,
		DisplayTitleService $displayTitleService
	) {
		$this->jsonCodec = $jsonCodec;
		$this->linkRenderer = $linkRenderer;
		$this->pageStore = $pageStore;
		$this->pageProps = $pageProps;
		$this->displayTitleService = $displayTitleService;
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setFunctionHook( 'displaycat', [ $this, 'onParserFunctionHook' ], Parser::SFH_NO_HASH );
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/CategoryViewer::generateLink
	 */
	public function onCategoryViewer__generateLink( $type, $title, $html, &$link ): bool {
		if ( $type !== 'page' ) {
			// not the type of category we want to mess with
			return true;
		}
		$categoryTitle = RequestContext::getMain()->getTitle();
		$id = $title->getArticleID();

		// get the category DB key to category display title map
		$property = $this->pageProps->getProperties( $title, 'displaycat' );
		// default to the display title of the page
		$this->displayTitleService->getDisplayTitle( $title, $text );
		if ( array_key_exists( $id, $property ) ) {
			$property = $this->jsonCodec->deserialize( $property[$id] );
			if ( array_key_exists( $categoryTitle->getDBkey(), $property ) ) {
				// there is a set category display title, use it
				$text = $property[$categoryTitle->getDBkey()];
			}
		}
		// change the link
		$link = $this->linkRenderer->makeLink( $title, $text );
		return false;
	}

	public function onParserFunctionHook(
		Parser $parser,
		string $title,
		?string $category = null,
		?string $flags = null
	): string|array {
		$title = $parser->doQuotes( $title );
		$title = $parser->killMarkers( $title );
		$title = Sanitizer::removeSomeTags( $title, [
			'removeTags' => self::BAD_TAGS,
		] );

		if ( $category === null ) {
			return self::generateError( $parser, 'displaycat-error-missing-category' );
		}
		$categoryTitle = $this->pageStore->getPageByText( $category, defaultNamespace: NS_CATEGORY );
		if ( $categoryTitle === null || $categoryTitle->getNamespace() !== NS_CATEGORY ) {
			$converter = $parser->getTargetLanguageConverter();
			return self::generateError( $parser, 'displaycat-error-invalid-category',
				$converter->markNoConversion( wfEscapeWikiText( $category ) ),
			);
		}

		// extract the previous displaycat property if it exists
		$previous = $parser->getOutput()->getPageProperty( 'displaycat' ) ?? '[]';
		$previous = $this->jsonCodec->deserialize( $previous );

		// if this hasn't been set before or we're suppressing errors
		if ( !array_key_exists( $categoryTitle->getDBkey(), $previous ) || $flags === 'noerror' ) {
			// append it to the list
			$parser->getOutput()->setPageProperty(
				'displaycat',
				$this->jsonCodec->serialize( [ $categoryTitle->getDBkey() => $title ] + $previous )
			);
			return '';
		}

		// if we're not replacing previous set category display titles
		if ( $flags === 'noreplace' ) {
			// do nothing
			return '';
		}

		$converter = $parser->getTargetLanguageConverter();
		return self::generateError( $parser, 'displaycat-error-already-specified',
			$converter->markNoConversion( wfEscapeWikiText( $category ) ),
		);
	}

	private static function generateError( Parser $parser, string $key, mixed ...$params ): string {
		$parser->addTrackingCategory( 'displaycat-page-error-category' );
		return '<strong class="error">'
			. wfMessage( $key, ...$params )->inContentLanguage()->parse()
			. '</strong>';
	}
}
