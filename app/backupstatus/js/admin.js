(function () {
    'use strict';

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'requesttoken': OC.requestToken,
                'Accept': 'application/json'
            },
            body: body || ''
        });
    }

    function setResult(result, success) {
        result.textContent = success
            ? '✔ ' + OC.L10N.translate('backupstatus', 'Connected')
            : '✖ ' + OC.L10N.translate('backupstatus', 'Connection failed');
        result.className = success ? 'success' : 'failed';
    }

    function ready() {
        const button = document.getElementById('backupstatus-save');
        const input = document.getElementById('backupstatus-url');
        const result = document.getElementById('backupstatus-result');
        const timeInput = document.getElementById('backupstatus-time');
        const dayInputs = document.querySelectorAll('input[name="backupstatus-day"]');
        if (!button || !input || !result || !timeInput || typeof OC === 'undefined') return;

        button.addEventListener('click', async function () {
            button.disabled = true;
            result.textContent = '⏳ ' + OC.L10N.translate('backupstatus', 'Testing connection…');
            result.className = 'testing';

            const body = new URLSearchParams();
            const selectedDays = Array.from(dayInputs)
                .filter((day) => day.checked)
                .map((day) => day.value);

            if (selectedDays.length === 0) {
                setResult(result, false);
                button.disabled = false;
                return;
            }

            body.set('url', input.value.trim());
            body.set('days', selectedDays.join(','));
            body.set('time', timeInput.value || '02:00');

            try {
                let response = await post(OC.generateUrl('/apps/backupstatus/settings'), body.toString());
                let data = await response.json();

                if (!data.success && !data.allowLocalRemoteServers) {
                    const accepted = window.confirm(
                        OC.L10N.translate('backupstatus', 'Nextcloud may be blocking this connection.') + '\n\n' +
                        OC.L10N.translate('backupstatus', 'Enable allow_local_remote_servers?')
                    );

                    if (accepted) {
                        response = await post(OC.generateUrl('/apps/backupstatus/settings/enable-local-remote'));
                        const enabled = await response.json();

                        if (response.ok && enabled.success) {
                            response = await post(OC.generateUrl('/apps/backupstatus/settings'), body.toString());
                            data = await response.json();
                        }
                    }
                }

                setResult(result, Boolean(data.success));
                window.dispatchEvent(new CustomEvent('backupstatus:changed'));
            } catch (e) {
                setResult(result, false);
            } finally {
                button.disabled = false;
            }
        });

        const requestButton = document.getElementById("backupstatus-request-provider");
        const useProvider = document.getElementById("backupstatus-use-provider");
        const consent = document.getElementById("backupstatus-provider-consent");
        const providerUrl = document.getElementById("backupstatus-provider-url");
        const providerEmail = document.getElementById("backupstatus-provider-email");

        const destinationType = document.getElementById("backupstatus-destination-type");
        const credentialMode = document.getElementById("backupstatus-credential-mode");
        const sshFields = document.getElementById("backupstatus-ssh-fields");
        const sshManualFields = document.getElementById("backupstatus-ssh-manual-fields");
        const sshManagedFields = document.getElementById("backupstatus-ssh-managed-fields");
        const awsFields = document.getElementById("backupstatus-aws-fields");
        const s3Fields = document.getElementById("backupstatus-s3-fields");

        function updateDestinationFields() {
            const destination = destinationType ? destinationType.value : "ssh";
            const mode = credentialMode ? credentialMode.value : "manual";

            if (sshFields) {
                sshFields.hidden = destination !== "ssh";
            }

            if (sshManualFields) {
                sshManualFields.hidden = destination !== "ssh" || mode !== "manual";
            }

            if (sshManagedFields) {
                sshManagedFields.hidden = destination !== "ssh" || mode !== "managed";
            }

            if (awsFields) {
                awsFields.hidden = destination !== "aws_s3";
            }

            if (s3Fields) {
                s3Fields.hidden = destination !== "s3_compatible";
            }

            if (useProvider) {
                useProvider.checked = destination === "ssh" && mode === "managed";
            }
        }

        if (destinationType) {
            destinationType.addEventListener("change", updateDestinationFields);
        }

        if (credentialMode) {
            credentialMode.addEventListener("change", updateDestinationFields);
        }

        updateDestinationFields();

        if (requestButton && consent && providerUrl && providerEmail) {
            requestButton.addEventListener("click", async function () {
                if (!consent.checked) {
                    window.alert(OC.L10N.translate("backupstatus", "Consent is required"));
                    return;
                }

                requestButton.disabled = true;
                requestButton.textContent = OC.L10N.translate("backupstatus", "Sending request…");

                const body = new URLSearchParams();
                body.set("consent", "1");
                body.set("providerUrl", providerUrl.value.trim());
                body.set("providerEmail", providerEmail.value.trim());

                try {
                    const response = await post(
                        OC.generateUrl("/apps/backupstatus/settings/request-provider"),
                        body.toString()
                    );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || "Request failed");
                    }

                    requestButton.textContent = OC.L10N.translate("backupstatus", "Request sent");
                    window.dispatchEvent(new CustomEvent("backupstatus:changed"));
                } catch (error) {
                    requestButton.disabled = false;
                    requestButton.textContent = OC.L10N.translate("backupstatus", "Request access");
                    window.alert(
                        error.message ||
                        OC.L10N.translate("backupstatus", "Request failed")
                    );
                }
            });
        }

        async function checkProviderStatus() {
            try {
                const response = await fetch(
                    OC.generateUrl("/apps/backupstatus/settings/provider-status"),
                    {
                        method: "GET",
                        credentials: "same-origin",
                        headers: {
                            "requesttoken": OC.requestToken,
                            "Accept": "application/json"
                        }
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    return;
                }

                if (!requestButton) {
                    return;
                }

                if (data.status === "pending") {
                    requestButton.disabled = true;
                    requestButton.textContent = OC.L10N.translate("backupstatus", "Pending approval");
                    return;
                }

                if (data.status === "approved") {
                    requestButton.disabled = true;
                    requestButton.textContent = OC.L10N.translate("backupstatus", "Approved");

                    if (result) {
                        result.textContent = "✔ " + OC.L10N.translate("backupstatus", "Connected");
                        result.className = "success";
                    }

                    window.dispatchEvent(new CustomEvent("backupstatus:changed"));
                    return;
                }

                if (data.status === "rejected") {
                    requestButton.disabled = false;
                    requestButton.textContent = OC.L10N.translate("backupstatus", "Request rejected");
                    return;
                }

                if (data.status === "expired") {
                    requestButton.disabled = false;
                    requestButton.textContent = OC.L10N.translate("backupstatus", "Request access");
                }
            } catch (error) {
                // Stil falen; statuscontrole mag de instellingenpagina niet blokkeren.
            }
        }

        if (requestButton) {
            checkProviderStatus();

            window.setInterval(checkProviderStatus, 15000);
        }

    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
