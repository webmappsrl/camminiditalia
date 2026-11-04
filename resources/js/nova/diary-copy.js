/**
 * Bottoni «Copia testo» e «Copia HTML» sul racconto del diario nel dettaglio Nova (oc:8169).
 * Il markup lo genera App\Nova\Diary::detailText(): il campo è reso con v-html, quindi niente
 * componente Vue, ma un solo listener delegato sul documento.
 *
 * - testo: costruito dagli elementi ammessi nel racconto, con una riga vuota fra i blocchi, «•» o
 *   il numero davanti alle voci degli elenchi e «>» davanti alle citazioni; innerText perderebbe
 *   i segni degli elenchi;
 * - HTML: innerHTML del racconto, cioè l'HTML già ripulito dal server.
 */
Nova.booting(() => {
    const inline = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            return node.textContent
        }
        if (node.nodeName === 'BR') {
            return '\n'
        }
        const text = Array.from(node.childNodes).map(inline).join('')
        const href = node.nodeName === 'A' ? node.getAttribute('href') : null
        // nel testo semplice il link si perderebbe: l'indirizzo va dopo le parole, se diverso
        return href && href !== text ? `${text} (${href})` : text
    }

    const blocks = (node) => Array.from(node.children).flatMap((child) => {
        switch (child.nodeName) {
            case 'UL':
            case 'OL':
                return [Array.from(child.children)
                    .map((li, i) => `${child.nodeName === 'OL' ? `${i + 1}.` : '•'} ${inline(li).trim()}`)
                    .join('\n')]
            case 'BLOCKQUOTE':
                return blocks(child).map((block) => block.split('\n').map((line) => `> ${line}`).join('\n'))
            case 'HR':
                return ['---']
            default:
                return [inline(child).trim()]
        }
    }).filter((block) => block !== '')

    const plainText = (body) => blocks(body).join('\n\n')

    const copy = async (value) => {
        try {
            await navigator.clipboard.writeText(value)
            return true
        } catch (e) {
            // clipboard API non disponibile (pagina non sicura o permesso negato)
            const area = document.createElement('textarea')
            area.value = value
            area.setAttribute('readonly', '')
            area.style.position = 'fixed'
            area.style.opacity = '0'
            document.body.appendChild(area)
            area.select()
            const ok = document.execCommand('copy')
            document.body.removeChild(area)
            return ok
        }
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-diary-copy]')
        if (!button) {
            return
        }
        const body = button.closest('.diary-rich-text-box')?.querySelector('.diary-rich-text')
        if (!body) {
            return
        }
        const value = button.dataset.diaryCopy === 'html' ? body.innerHTML : plainText(body)
        if (await copy(value)) {
            Nova.success(button.dataset.diaryCopied)
        }
    })
})
