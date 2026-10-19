/**
 * Secondo modale dell'azione Nova "Decidi richiesta" (oc:8671): mostra il
 * riepilogo della decisione e, solo alla conferma, chiama
 * POST /nova-vendor/certification-decision/confirm.
 *
 * Stesso pattern di wm-package/resources/js/geohub-where-selection.js:
 * render function (nessun build), stili inline, classi Tailwind dei bottoni
 * copiate da quel file perché già presenti nel CSS di Nova, Teleport verso
 * <body>. Il nome del componente DEVE combaciare con
 * DecideCertificationRequest::CONFIRM_MODAL_COMPONENT (PHP). Tutti i testi
 * arrivano già tradotti nel payload (`labels`).
 */
Nova.booting((app) => {
    const h = Vue.h

    const BUTTON_BASE = 'border text-left appearance-none cursor-pointer rounded text-sm font-bold inline-flex items-center justify-center h-9 px-3'
    const BUTTON_VARIANTS = {
        primary: 'shadow bg-primary-500 border-primary-500 text-white hover:bg-primary-400 hover:border-primary-400',
        danger: 'shadow bg-red-500 border-red-500 text-white hover:bg-red-400 hover:border-red-400',
        secondary: 'bg-transparent border-transparent text-gray-600 hover:bg-gray-100',
    }

    const button = (label, variant, onClick, disabled) => h('button', {
        type: 'button',
        class: `${BUTTON_BASE} ${BUTTON_VARIANTS[variant] || BUTTON_VARIANTS.primary}`,
        style: disabled ? 'opacity:0.5;pointer-events:none' : '',
        disabled: !!disabled,
        onClick,
    }, label)

    app.component('certification-decision-confirm-modal', {
        props: {
            data: { type: Object, required: true },
        },

        emits: ['confirm', 'close'],

        data() {
            return {
                loading: false,
                result: null,
                error: null,
            }
        },

        computed: {
            labels() {
                return this.data.labels || {}
            },
            isApprove() {
                return !!this.data.is_approve
            },
        },

        methods: {
            async submit() {
                if (this.loading) {
                    return
                }

                this.loading = true
                this.error = null

                try {
                    const response = await Nova.request().post('/nova-vendor/certification-decision/confirm', {
                        certification_request_id: this.data.certification_request_id,
                        outcome: this.data.outcome,
                        ec_track_ids: this.data.ec_track_ids || [],
                        decision_note: this.data.note,
                    })
                    this.result = response.data
                    Nova.success(response.data.message)
                } catch (e) {
                    this.error = (e.response && e.response.data && e.response.data.message) || this.labels.unexpected_error
                } finally {
                    this.loading = false
                }
            },

            close() {
                if (this.loading) {
                    return
                }

                this.$emit('close')

                // Dopo una decisione salvata la pagina va ricaricata: stato,
                // tappe validate e disponibilità dell'azione sono cambiati.
                if (this.result) {
                    window.location.reload()
                }
            },
        },

        render() {
            const overlay = h('div', {
                style: 'position:absolute;inset:0;background-color:rgba(0,0,0,0.5)',
                onClick: this.close,
            })

            let body
            if (this.result) {
                body = h('div', { style: 'padding:16px 0' }, this.result.message)
            } else if (this.error) {
                body = h('div', { style: 'padding:16px 0;color:#ef4444' }, this.error)
            } else {
                const parts = [h('p', { style: 'margin-bottom:12px' }, this.labels.summary)]

                if (this.isApprove) {
                    parts.push(h('ul', { style: 'max-height:280px;overflow-y:auto;list-style:disc;padding-left:20px;margin-bottom:12px' },
                        (this.data.track_labels || []).map((label, index) => h('li', { key: index, style: 'padding:2px 0' }, label))))
                }

                if (this.data.note) {
                    parts.push(h('div', { style: 'margin-bottom:12px' }, [
                        h('div', { style: 'font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.7' }, this.labels.note),
                        h('div', { style: 'white-space:pre-line' }, this.data.note),
                    ]))
                }

                parts.push(h('p', { style: 'font-weight:700;color:#b45309' }, this.labels.warning))

                body = h('div', { style: 'padding:8px 0' }, parts)
            }

            const footer = (this.result || this.error)
                ? h('div', { style: 'display:flex;justify-content:flex-end;margin-top:16px' }, [
                    button(this.labels.close, 'primary', this.close),
                ])
                : h('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:16px' }, [
                    button(this.labels.cancel, 'secondary', this.close, this.loading),
                    button(this.loading ? this.labels.confirming : this.labels.confirm, this.isApprove ? 'primary' : 'danger', this.submit, this.loading),
                ])

            const panel = h('div', {
                style: 'position:relative;background:white;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.35);width:100%;max-width:32rem;padding:24px;max-height:90vh;overflow-y:auto',
                class: 'dark:bg-gray-800 dark:text-white',
            }, [
                h('h3', { style: 'font-size:1.25rem;font-weight:400;margin-bottom:8px' }, this.labels.title),
                body,
                footer,
            ])

            const wrapper = h('div', {
                style: 'position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;padding:16px',
            }, [overlay, panel])

            return h(Vue.Teleport, { to: 'body' }, [wrapper])
        },
    })
})
