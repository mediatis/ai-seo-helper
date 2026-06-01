import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

/**
 * Per-field translation control: translates the current field from its language
 * parent into the record language, shows an inline preview and applies the
 * accepted value to the form field (plain inputs/textareas and CKEditor5 RTE).
 */
class AiTranslate {
    constructor() {
        this.bindButtons();
    }

    bindButtons() {
        document.querySelectorAll(".ai-seo-helper-translate-btn").forEach((button) => {
            if (button.dataset.aiTranslateBound === "1") {
                return;
            }
            button.dataset.aiTranslateBound = "1";
            button.addEventListener("click", (ev) => {
                ev.preventDefault();
                this.requestTranslation(button);
            });
        });
    }

    requestTranslation(button) {
        const table = button.getAttribute("data-table");
        const uid = button.getAttribute("data-uid");
        const field = button.getAttribute("data-field-name");
        const base = "data[" + table + "][" + uid + "][" + field + "]";

        // The DOM is the source of truth for whether this field is an RTE: tt_content
        // enables RTE per content type via columnsOverrides, so server-side TCA on the
        // base column cannot tell. The flag is sent so the AI prompt preserves HTML.
        const isRichtext = this.findEditor(base) !== null;

        Notification.info(
            TYPO3.lang["AiSeoHelper.translate.start"] || "Translating",
            TYPO3.lang["AiSeoHelper.translate.start.info"] || "Translation in progress. Please wait...",
            8
        );

        new AjaxRequest(TYPO3.settings.ajaxUrls["aiseohelper_translate"])
            .post({ table: table, uid: uid, field: field, isRichtext: isRichtext ? "1" : "0" })
            .then(async (response) => {
                const body = JSON.parse(await response.resolve());
                if (!body.success) {
                    Notification.error(
                        TYPO3.lang["AiSeoHelper.notification.generation.requestError"] || "Request error",
                        body.error || ""
                    );
                    return;
                }
                this.showPreview(button, base, isRichtext, body.output);
            })
            .catch((error) => {
                Notification.error(
                    TYPO3.lang["AiSeoHelper.notification.generation.error"] || "Unexpected error",
                    String(error)
                );
            });
    }

    showPreview(button, base, isRichtext, output) {
        const fieldItem =
            button.closest(".formengine-field-item") || button.closest(".t3js-formengine-field-item");
        if (!fieldItem) {
            return;
        }

        const existing = fieldItem.querySelector(".ai-seo-helper-translation");
        if (existing) {
            existing.remove();
        }

        const box = document.createElement("div");
        box.className = "ai-seo-helper-translation callout callout-info mt-2";

        const heading = document.createElement("p");
        heading.className = "mb-1 fw-bold";
        heading.textContent = TYPO3.lang["AiSeoHelper.translate.previewHeader"] || "Suggested translation";
        box.appendChild(heading);

        const preview = document.createElement("div");
        preview.className = "ai-seo-helper-translation-preview mb-2";
        if (isRichtext) {
            preview.innerHTML = output;
        } else {
            preview.textContent = output;
        }
        box.appendChild(preview);

        const accept = document.createElement("button");
        accept.type = "button";
        accept.className = "btn btn-primary btn-sm me-2";
        accept.textContent = TYPO3.lang["AiSeoHelper.translate.accept"] || "Accept";
        accept.addEventListener("click", () => {
            this.applyValue(base, output);
            box.remove();
            Notification.success(TYPO3.lang["AiSeoHelper.translate.applied"] || "Translation applied", "", 5);
        });
        box.appendChild(accept);

        const reject = document.createElement("button");
        reject.type = "button";
        reject.className = "btn btn-default btn-sm";
        reject.textContent = TYPO3.lang["AiSeoHelper.translate.reject"] || "Discard";
        reject.addEventListener("click", () => box.remove());
        box.appendChild(reject);

        fieldItem.appendChild(box);
    }

    applyValue(base, value) {
        // RTE first: if a CKEditor5 instance owns this field, the textarea is one-way
        // (editor -> textarea) and writing the textarea would be discarded on save.
        const editor = this.findEditor(base);
        if (editor) {
            editor.setData(value);
            return;
        }

        // Plain input: a visible field mirrored into a hidden field by FormEngine.
        const visible = document.querySelector('[data-formengine-input-name="' + base + '"]');
        const real = document.querySelector(
            'input[name="' + base + '"], textarea[name="' + base + '"]'
        );

        if (visible) {
            visible.value = value;
            visible.dispatchEvent(new Event("input", { bubbles: true }));
            visible.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (real && real !== visible) {
            real.value = value;
            real.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    /**
     * Resolve the CKEditor5 instance bound to a field, or null for non-RTE fields.
     * CKEditor5 attaches the editor to its editable DOM root via `ckeditorInstance`;
     * setData() on it also updates the source textarea and marks the form dirty.
     *
     * @returns {?object} the CKEditor instance, or null
     */
    findEditor(base) {
        const textarea = document.querySelector('textarea[name="' + base + '"]');
        if (!textarea) {
            return null;
        }
        const wrapper = textarea.closest("typo3-rte-ckeditor-ckeditor5");
        if (!wrapper) {
            return null;
        }
        const editable =
            wrapper.querySelector(".ck-content") || wrapper.querySelector(".ck-editor__editable");
        if (editable && editable.ckeditorInstance) {
            return editable.ckeditorInstance;
        }
        // Fallback: scan descendants for the attached instance.
        const candidates = wrapper.querySelectorAll("*");
        for (let i = 0; i < candidates.length; i++) {
            if (candidates[i].ckeditorInstance) {
                return candidates[i].ckeditorInstance;
            }
        }
        return null;
    }
}

export default new AiTranslate();
