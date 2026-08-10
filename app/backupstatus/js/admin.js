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
        const resendButton = document.getElementById("backupstatus-resend-provider");
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

        const recoveryAccessTitle = document.getElementById("backupstatus-recovery-access-title");
        const recoveryAccessText = document.getElementById("backupstatus-recovery-access-text");
        const removeRemote = document.getElementById("backupstatus-remove-remote");
        const removeRemoteLabel = document.getElementById("backupstatus-remove-remote-label");
        const removeConfirmation = document.getElementById("backupstatus-remove-confirmation");
        const removeConfirmText = document.getElementById("backupstatus-remove-confirm-text");
        const removeLocal = document.getElementById("backupstatus-remove-local");
        const removeButton = document.getElementById("backupstatus-remove-button");

        function updateRecoveryAndRemoval() {
            const destination = destinationType ? destinationType.value : "ssh";
            const mode = credentialMode ? credentialMode.value : "manual";

            if (destination === "aws_s3") {
                if (recoveryAccessTitle) recoveryAccessTitle.textContent =
                    OC.L10N.translate("backupstatus", "Recover AWS S3 access");
                if (recoveryAccessText) recoveryAccessText.textContent =
                    OC.L10N.translate("backupstatus",
                        "Reconnect to the existing AWS S3 bucket and backup prefix using valid AWS credentials.");
                if (removeRemoteLabel) removeRemoteLabel.textContent =
                    OC.L10N.translate("backupstatus",
                        "Remove this installation's backup data from AWS S3");
            } else if (destination === "s3_compatible") {
                if (recoveryAccessTitle) recoveryAccessTitle.textContent =
                    OC.L10N.translate("backupstatus", "Recover S3 storage access");
                if (recoveryAccessText) recoveryAccessText.textContent =
                    OC.L10N.translate("backupstatus",
                        "Reconnect to the existing S3-compatible bucket and backup prefix using valid storage credentials.");
                if (removeRemoteLabel) removeRemoteLabel.textContent =
                    OC.L10N.translate("backupstatus",
                        "Remove this installation's backup data from S3-compatible storage");
            } else if (mode === "managed") {
                if (recoveryAccessTitle) recoveryAccessTitle.textContent =
                    OC.L10N.translate("backupstatus", "Recover provider access");
                if (recoveryAccessText) recoveryAccessText.textContent =
                    OC.L10N.translate("backupstatus",
                        "Request recovery of an existing Backup Manager client identity. A replacement key requires provider approval.");
                if (removeRemoteLabel) removeRemoteLabel.textContent =
                    OC.L10N.translate("backupstatus",
                        "Request deletion of this client's backup data from the backup provider");
            } else {
                if (recoveryAccessTitle) recoveryAccessTitle.textContent =
                    OC.L10N.translate("backupstatus", "Recover SSH backup access");
                if (recoveryAccessText) recoveryAccessText.textContent =
                    OC.L10N.translate("backupstatus",
                        "Reconnect this installation to the existing SSH backup location using valid credentials.");
                if (removeRemoteLabel) removeRemoteLabel.textContent =
                    OC.L10N.translate("backupstatus",
                        "Remove this installation's backup data from the SSH server");
            }

            const remote = Boolean(removeRemote && removeRemote.checked);

            if (removeConfirmation) {
                removeConfirmation.hidden = !remote;
            }

            if (removeButton) {
                removeButton.disabled = remote
                    ? !(removeConfirmText && removeConfirmText.value === "DELETE")
                    : !(removeLocal && removeLocal.checked);
            }
        }

        if (destinationType) {
            destinationType.addEventListener("change", updateRecoveryAndRemoval);
        }

        if (credentialMode) {
            credentialMode.addEventListener("change", updateRecoveryAndRemoval);
        }

        if (removeRemote) {
            removeRemote.addEventListener("change", updateRecoveryAndRemoval);
        }

        if (removeLocal) {
            removeLocal.addEventListener("change", updateRecoveryAndRemoval);
        }

        if (removeConfirmText) {
            removeConfirmText.addEventListener("input", updateRecoveryAndRemoval);
        }

        updateRecoveryAndRemoval();

        if (removeButton) {
            removeButton.addEventListener("click", async function () {
                const removeLocalEnabled = Boolean(removeLocal && removeLocal.checked);
                const removeRemoteEnabled = Boolean(removeRemote && removeRemote.checked);
                const confirmation = removeConfirmText ? removeConfirmText.value.trim() : "";

                if (!removeLocalEnabled && !removeRemoteEnabled) {
                    window.alert(
                        OC.L10N.translate("backupstatus", "Select at least one removal option")
                    );
                    return;
                }

                if (removeRemoteEnabled && confirmation !== "DELETE") {
                    window.alert(
                        OC.L10N.translate(
                            "backupstatus",
                            "Type DELETE to confirm remote backup deletion"
                        )
                    );
                    return;
                }

                const accepted = window.confirm(
                    removeRemoteEnabled
                        ? OC.L10N.translate(
                            "backupstatus",
                            "This will permanently request deletion of the remote backup data for this Backup Manager client. Continue?"
                        )
                        : OC.L10N.translate(
                            "backupstatus",
                            "Remove the local Backup Manager installation?"
                        )
                );

                if (!accepted) {
                    return;
                }

                removeButton.disabled = true;
                removeButton.textContent =
                    OC.L10N.translate("backupstatus", "Removing…");

                const body = new URLSearchParams();
                body.set("removeLocal", removeLocalEnabled ? "1" : "0");
                body.set("removeRemote", removeRemoteEnabled ? "1" : "0");
                body.set("confirm", confirmation);

                try {
                    const response = await post(
                        OC.generateUrl("/apps/backupstatus/settings/remove"),
                        body.toString()
                    );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || "Removal failed");
                    }

                    if (
                        data.remote
                        && data.remote.type === "provider"
                        && data.remote.status === "pending"
                    ) {
                        window.alert(
                            OC.L10N.translate(
                                "backupstatus",
                                "Remote deletion request submitted. It is awaiting provider administrator approval."
                            )
                        );
                    }

                    if (data.localRemovalScheduled) {
                        window.alert(
                            OC.L10N.translate(
                                "backupstatus",
                                "Backup Manager removal has been scheduled. This page will stop working shortly."
                            )
                        );

                        window.setTimeout(function () {
                            window.location.href = OC.generateUrl("/settings/admin");
                        }, 3500);

                        return;
                    }

                    removeButton.disabled = false;
                    removeButton.textContent =
                        OC.L10N.translate("backupstatus", "Remove Backup Manager");
                } catch (error) {
                    removeButton.disabled = false;
                    removeButton.textContent =
                        OC.L10N.translate("backupstatus", "Remove Backup Manager");

                    window.alert(
                        error.message ||
                        OC.L10N.translate("backupstatus", "Removal failed")
                    );
                }
            });
        }

        if (resendButton) {
            resendButton.addEventListener("click", async function () {
                resendButton.disabled = true;
                resendButton.textContent =
                    OC.L10N.translate("backupstatus", "Sending…");

                try {
                    const response = await post(
                        OC.generateUrl("/apps/backupstatus/settings/provider-resend"),
                        ""
                    );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(
                            data.error || "Notification could not be sent"
                        );
                    }

                    resendButton.textContent =
                        OC.L10N.translate("backupstatus", "Notification sent");

                    window.setTimeout(function () {
                        resendButton.disabled = false;
                        resendButton.textContent =
                            OC.L10N.translate("backupstatus", "Resend notification");
                    }, 2500);
                } catch (error) {
                    resendButton.disabled = false;
                    resendButton.textContent =
                        OC.L10N.translate("backupstatus", "Resend notification");

                    window.alert(
                        error.message ||
                        OC.L10N.translate(
                            "backupstatus",
                            "Notification could not be sent"
                        )
                    );
                }
            });
        }

    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
