<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FullTextSearchResultListener;
use OCP\EventDispatcher\GenericEvent;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class FullTextSearchResultListenerTest extends TestCase {
	private const ICON = '/apps/etherpad_nextcloud/img/filetypes/etherpad-nextcloud-pad.svg';

	public function testGivesAPadResultTheAppsOwnIcon(): void {
		$document = $this->document('Meeting.pad', ['thumbUrl' => '', 'icon' => '/core/img/filetypes/text.svg']);
		$document->expects(self::once())
			->method('setInfoArray')
			->with('unified', ['thumbUrl' => '', 'icon' => self::ICON]);

		$this->listener()->handle($this->searchResultEvent([$document]));
	}

	public function testLeavesEveryOtherResultAlone(): void {
		$document = $this->document('Notes.txt', ['icon' => '/core/img/filetypes/text.svg']);
		$document->expects(self::never())->method('setInfoArray');

		$this->listener()->handle($this->searchResultEvent([$document]));
	}

	public function testIgnoresOtherGenericEvents(): void {
		$document = $this->document('Meeting.pad', []);
		$document->expects(self::never())->method('setInfoArray');

		$this->listener()->handle(new GenericEvent('Files_FullTextSearch.onFileIndexing', [
			'result' => $this->searchResult([$document]),
		]));
	}

	public function testIgnoresAnEventWithoutAResult(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::never())->method('imagePath');

		(new FullTextSearchResultListener($urlGenerator))
			->handle(new GenericEvent('Files_FullTextSearch.onSearchResult', ['result' => 'not a result']));
	}

	/** The URL is the same for every hit, so it is built once per result. */
	public function testResolvesTheIconOncePerResult(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::once())->method('imagePath')->willReturn(self::ICON);

		(new FullTextSearchResultListener($urlGenerator))->handle($this->searchResultEvent([
			$this->document('One.pad', []),
			$this->document('Two.pad', []),
			$this->document('Three.pad', []),
		]));
	}

	private function listener(): FullTextSearchResultListener {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn(self::ICON);
		return new FullTextSearchResultListener($urlGenerator);
	}

	/** @param array<string,mixed> $unified */
	private function document(string $title, array $unified): IIndexDocument {
		$document = $this->createMock(IIndexDocument::class);
		$document->method('getTitle')->willReturn($title);
		$document->method('getInfoArray')->willReturn($unified);
		return $document;
	}

	/** @param IIndexDocument[] $documents */
	private function searchResult(array $documents): ISearchResult {
		$result = $this->createMock(ISearchResult::class);
		$result->method('getDocuments')->willReturn($documents);
		return $result;
	}

	/** @param IIndexDocument[] $documents */
	private function searchResultEvent(array $documents): GenericEvent {
		return new GenericEvent('Files_FullTextSearch.onSearchResult', [
			'result' => $this->searchResult($documents),
		]);
	}
}
