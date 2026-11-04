<?php

namespace Tests\Unit\Services;

use App\Services\DiaryTextService;
use Tests\TestCase;

/**
 * Pulizia e misura del racconto del diario (oc:8169, ciclo 3).
 */
class DiaryTextServiceTest extends TestCase
{
    private DiaryTextService $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->text = new DiaryTextService;
    }

    public function test_keeps_allowed_formatting(): void
    {
        $html = '<h2>Giorno 1</h2><h3>Mattina</h3><p>Ciao <strong>tu</strong>, <em>sì</em> e <s>no</s>.<br>A capo</p>'
            .'<ul><li><p>acqua</p></li></ul><ol><li><p>pane</p></li></ol><blockquote><p>fatica</p></blockquote><hr>'
            .'<p><a href="https://camminiditalia.it">sito</a> e <a href="mailto:x@y.it">scrivi</a></p>';

        $clean = $this->text->clean($html);

        foreach (['<h2>Giorno 1</h2>', '<h3>Mattina</h3>', '<strong>tu</strong>', '<em>sì</em>', '<s>no</s>', '<br />',
            '<ul><li><p>acqua</p></li></ul>', '<ol><li><p>pane</p></li></ol>', '<blockquote><p>fatica</p></blockquote>', '<hr />',
            '<a href="https://camminiditalia.it">sito</a>', '<a href="mailto:x@y.it">scrivi</a>'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
    }

    public function test_removes_everything_else_and_keeps_the_text(): void
    {
        $clean = $this->text->clean(
            '<p style="color:red" onclick="x()">Uno <span class="a">due</span> <u>tre</u> <code>q</code></p>'
            .'<script>alert(1)</script><img src="a.png" onerror="x()"><h1>Grande</h1>'
        );

        $this->assertSame('<p>Uno due tre q</p>Grande', $clean);
    }

    public function test_links_without_an_absolute_allowed_scheme_lose_the_tag(): void
    {
        $this->assertSame('<p>x y z</p>', $this->text->clean(
            '<p><a href="javascript:alert(1)">x</a> <a href="/relativo">y</a> <a href="www.a.it">z</a></p>'
        ));
    }

    public function test_plain_text_becomes_paragraphs(): void
    {
        $this->assertSame(
            '<p>Primo<br />giorno</p><p>Secondo &amp; &lt;ciao&gt;</p>',
            $this->text->clean("Primo\ngiorno\n\nSecondo & <ciao>")
        );
    }

    public function test_empty_text_is_null(): void
    {
        $this->assertNull($this->text->clean('<p></p><p> </p><p><br></p>'));
        $this->assertNull($this->text->clean('   '));
        $this->assertNull($this->text->clean(null));
        // righe vuote incollate da Word: spazi non separabili
        $this->assertNull($this->text->clean("<p>&nbsp;</p><p>\u{00A0} </p>"));
    }

    public function test_visible_length_counts_characters_without_tags(): void
    {
        $this->assertSame(5, $this->text->visibleLength('<p>a &amp; b</p>'));
        $this->assertSame(3, $this->text->visibleLength('<p><strong>è</strong>🙂x</p>'));
    }

    public function test_plain_text_joins_blocks(): void
    {
        $this->assertSame('Giorno 1 Via & basta', $this->text->plainText('<h2>Giorno 1</h2><p>Via &amp; basta</p>'));
    }
}
