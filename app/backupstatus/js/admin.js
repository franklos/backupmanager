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

        if (requestButton && requestButton.disabled) {
            checkProviderStatus();

            window.setInterval(checkProviderStatus, 15000);
        }

        const restoreButton = document.getElementById("backupstatus-recovery-restore");
    const recoveryAccessTitle = document.getElementById("backupstatus-recovery-access-title");
        const recoveryAccessText = document.getElementById("backupstatus-recovery-access-text");
        const removeRemote = document.getElementById("backupstatus-remove-remote");
        const removeRemoteLabel = document.getElementById("backupstatus-remove-remote-label");
        const removeConfirmation = document.getElementById("backupstatus-remove-confirmation");
        const removeConfirmText = document.getElementById("backupstatus-remove-confirm-text");
        const removeLocal = document.getElementById("backupstatus-remove-local");
        const removeButton = document.getElementById("backupstatus-remove-button");

    
    const restorePanel = document.getElementById("backupstatus-restore-panel");
    const restoreDatabaseRow = document.getElementById("backupstatus-restore-database-row");
    const restoreDatabase = document.getElementById("backupstatus-restore-database");
    const restoreContinue = document.getElementById("backupstatus-restore-continue");
    const restoreCancel = document.getElementById("backupstatus-restore-cancel");

    function updateRestoreSelection() {
        const selected = document.querySelector(
            'input[name="backupstatus-restore-type"]:checked'
        );

        const needsDatabase =
            selected &&
            (selected.value === "database" || selected.value === "complete");

        if (restoreDatabaseRow) {
            restoreDatabaseRow.hidden = !needsDatabase;
        }

        if (restoreContinue) {
            restoreContinue.disabled =
                !selected ||
                (needsDatabase && (!restoreDatabase || restoreDatabase.value === ""));
        }
    }

    if (restoreButton) {
        restoreButton.disabled = false;

        restoreButton.addEventListener("click", async function () {
            restoreButton.disabled = true;

            try {
                const response = await fetch(
                    OC.generateUrl("/apps/backupstatus/settings/restore-inventory"),
                    {
                        method: "GET",
                        headers: {
                            "Accept": "application/json",
                            "requesttoken": OC.requestToken,
                        },
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(
                        data.error || "Restore inventory could not be loaded"
                    );
                }

                restoreDatabase.innerHTML = "";

                const placeholder = document.createElement("option");
                placeholder.value = "";
                placeholder.textContent = "Select database backup";
                restoreDatabase.appendChild(placeholder);

                for (const backup of (data.database || [])) {
                    const option = document.createElement("option");
                    option.value = backup;
                    option.textContent = backup;
                    restoreDatabase.appendChild(option);
                }

                restorePanel.hidden = false;
                updateRestoreSelection();

            } catch (error) {
                window.alert(error.message);
            } finally {
                restoreButton.disabled = false;
            }
        });
    }

    document.querySelectorAll(
        'input[name="backupstatus-restore-type"]'
    ).forEach(function (radio) {
        radio.addEventListener("change", updateRestoreSelection);
    });

    if (restoreDatabase) {
        restoreDatabase.addEventListener("change", updateRestoreSelection);
    }

    if (restoreCancel) {
        restoreCancel.addEventListener("click", function () {
        });
    }

if (restoreContinue) {
    restoreContinue.addEventListener("click", async function () {
        const selected = document.querySelector(
            'input[name="backupstatus-restore-type"]:checked'
        );

        if (!selected) {
            return;
        }

        const type = selected.value;
        const databaseBackup =
            restoreDatabase ? restoreDatabase.value : "";

        if (
            (type === "database" || type === "complete") &&
            databaseBackup === ""
        ) {
            window.alert("Select a database backup first.");
            return;
        }

        const confirmed = window.confirm(
            "WARNING: This restore will overwrite existing Nextcloud data.\n\n" +
            "Restore type: " + type +
            (databaseBackup ? "\nDatabase: " + databaseBackup : "") +
            "\n\nContinue?"
        );

        if (!confirmed) {
            return;
        }

        restoreContinue.disabled = true;

        const restoreBusyStarted = Date.now();

        restoreContinue.disabled = true;
        restoreContinue.textContent = "Busy…";

        // Geef de browser eerst tijd om "Busy…" zichtbaar te renderen.
        await new Promise(resolve =>
            requestAnimationFrame(() =>
                requestAnimationFrame(resolve)
            )
        );

        try {
            const body = new URLSearchParams();
            body.append("type", type);
            body.append("databaseBackup", databaseBackup);
            body.append("confirm", "RESTORE");

            const response = await fetch(
                OC.generateUrl("/apps/backupstatus/settings/restore"),
                {
                    method: "POST",
                    headers: {
                        "Accept": "application/json",
                        "Content-Type": "application/x-www-form-urlencoded",
                        "requesttoken": OC.requestToken,
                    },
                    body: body.toString(),
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || "Restore failed");
            }

            const busyElapsed = Date.now() - restoreBusyStarted;

            if (busyElapsed < 1500) {
                await new Promise(resolve =>
                    setTimeout(resolve, 1500 - busyElapsed)
                );
            }

            restoreContinue.textContent = "Succes";

            window.alert("Restore completed successfully.");

        } catch (error) {
            restoreContinue.textContent = "Failed";

            window.alert(error.message || "Restore failed");
        } finally {
            restoreContinue.disabled = false;
            updateRestoreSelection();
        }
    });
}


    const disasterButton = document.getElementById("backupstatus-recovery-disaster");
    const disasterPanel = document.getElementById("backupstatus-disaster-panel");
    const disasterDatabase = document.getElementById("backupstatus-disaster-database");
    const disasterVerify = document.getElementById("backupstatus-disaster-verify");
    const disasterStart = document.getElementById("backupstatus-disaster-start");
    const disasterCancel = document.getElementById("backupstatus-disaster-cancel");

    async function loadDisasterInventory() {
        const response = await fetch(
            OC.generateUrl("/apps/backupstatus/settings/restore-inventory"),
            {
                method: "GET",
                headers: {
                    "Accept": "application/json",
                    "requesttoken": OC.requestToken,
                },
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.error || "Disaster recovery inventory could not be loaded"
            );
        }

        disasterDatabase.innerHTML = "";

        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Select database backup";
        disasterDatabase.appendChild(placeholder);

        for (const backup of (data.database || [])) {
            const option = document.createElement("option");
            option.value = backup;
            option.textContent = backup;
            disasterDatabase.appendChild(option);
        }
    }

    if (disasterButton) {
        disasterButton.disabled = false;

        disasterButton.addEventListener("click", async function () {
            disasterButton.disabled = true;

            try {
                await loadDisasterInventory();
                disasterPanel.hidden = false;
                disasterVerify.disabled = false;
                disasterStart.disabled = true;
            } catch (error) {
                window.alert(error.message);
            } finally {
                disasterButton.disabled = false;
            }
        });
    }

    if (disasterDatabase) {
        disasterDatabase.addEventListener("change", function () {
            disasterVerify.disabled = disasterDatabase.value === "";
            disasterStart.disabled = true;
            disasterVerify.textContent = "Preflight";
            disasterStart.textContent = "Start";
        });
    }

    if (disasterVerify) {
        disasterVerify.addEventListener("click", async function () {
            if (!disasterDatabase.value) {
                return;
            }

            disasterVerify.disabled = true;
            disasterVerify.textContent = "Busy…";

            try {
                const body = new URLSearchParams();
                body.append("mode", "verify");
                body.append("databaseBackup", disasterDatabase.value);

                const response = await fetch(
                    OC.generateUrl("/apps/backupstatus/settings/disaster-recovery"),
                    {
                        method: "POST",
                        headers: {
                            "Accept": "application/json",
                            "Content-Type": "application/x-www-form-urlencoded",
                            "requesttoken": OC.requestToken,
                        },
                        body: body.toString(),
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || "Preflight failed");
                }

                disasterVerify.textContent = "Succes";
                disasterStart.disabled = false;

            } catch (error) {
                disasterVerify.textContent = "Failed";
                window.alert(error.message || "Preflight failed");
            } finally {
                disasterVerify.disabled = false;
            }
        });
    }

    if (disasterStart) {
        disasterStart.addEventListener("click", async function () {
            if (!disasterDatabase.value) {
                return;
            }

            const confirmed = window.confirm(
                "WARNING: Disaster recovery will overwrite the saved configuration, database and data.\n\n" +
                "Database: " + disasterDatabase.value +
                "\n\nContinue?"
            );

            if (!confirmed) {
                return;
            }

            disasterStart.disabled = true;
            disasterStart.textContent = "Busy…";

            try {
                const body = new URLSearchParams();
                body.append("mode", "restore");
                body.append("databaseBackup", disasterDatabase.value);
                body.append("confirm", "RESTORE");

                const response = await fetch(
                    OC.generateUrl("/apps/backupstatus/settings/disaster-recovery"),
                    {
                        method: "POST",
                        headers: {
                            "Accept": "application/json",
                            "Content-Type": "application/x-www-form-urlencoded",
                            "requesttoken": OC.requestToken,
                        },
                        body: body.toString(),
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || "Disaster recovery failed");
                }

                disasterStart.textContent = "Succes";

            } catch (error) {
                disasterStart.textContent = "Failed";
                window.alert(error.message || "Disaster recovery failed");
            } finally {
                disasterStart.disabled = false;
            }
        });
    }

    if (disasterCancel) {
        disasterCancel.addEventListener("click", function () {
            disasterPanel.hidden = true;
        });
    }


    const recoveryAccessButton = document.getElementById("backupstatus-recovery-access");

    let recoveryPollTimer = null;

    async function checkRecoveryStatus() {
        try {
            const response = await fetch(
                OC.generateUrl("/apps/backupstatus/settings/recovery-status"),
                {
                    method: "GET",
                    headers: {
                        "Accept": "application/json",
                        "requesttoken": OC.requestToken,
                    },
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || "Recovery status check failed");
            }

            if (data.status === "approved") {
                if (recoveryPollTimer) {
                    window.clearInterval(recoveryPollTimer);
                    recoveryPollTimer = null;
                }

                recoveryAccessButton.disabled = false;
                recoveryAccessButton.textContent = "Succes";
                return;
            }

            if (data.status === "rejected" || data.status === "expired") {
                if (recoveryPollTimer) {
                    window.clearInterval(recoveryPollTimer);
                    recoveryPollTimer = null;
                }

                recoveryAccessButton.disabled = false;
                recoveryAccessButton.textContent =
                    data.status === "rejected" ? "Rejected" : "Expired";
                return;
            }

            recoveryAccessButton.textContent = "Pending…";

        } catch (error) {
            recoveryAccessButton.disabled = false;
            recoveryAccessButton.textContent = "Failed";
            window.alert(error.message || "Recovery status check failed");

            if (recoveryPollTimer) {
                window.clearInterval(recoveryPollTimer);
                recoveryPollTimer = null;
            }
        }
    }

    if (recoveryAccessButton) {
        recoveryAccessButton.disabled = false;

        recoveryAccessButton.addEventListener("click", async function () {
            recoveryAccessButton.disabled = true;
            recoveryAccessButton.textContent = "Busy…";

            try {
                const body = new URLSearchParams();

                const response = await fetch(
                    OC.generateUrl("/apps/backupstatus/settings/request-recovery"),
                    {
                        method: "POST",
                        headers: {
                            "Accept": "application/json",
                            "Content-Type": "application/x-www-form-urlencoded",
                            "requesttoken": OC.requestToken,
                        },
                        body: body.toString(),
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || "Recovery request failed");
                }

                recoveryAccessButton.textContent = "Pending…";

                await checkRecoveryStatus();

                recoveryPollTimer = window.setInterval(
                    checkRecoveryStatus,
                    15000
                );

            } catch (error) {
                recoveryAccessButton.disabled = false;
                recoveryAccessButton.textContent = "Failed";
                window.alert(error.message || "Recovery request failed");
            }
        });
    }

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

    async function loadManagementClients() {
        const container = document.getElementById("backupstatus-clients");

        if (!container) {
            return;
        }

        try {
            const response = await fetch(
                OC.generateUrl("/apps/backupstatus/settings/management-clients"),
                {
                    method: "GET",
                    headers: {
                        "Accept": "application/json",
                        "requesttoken": OC.requestToken
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || "Unable to load clients");
            }

            container.textContent = "";

            if (!Array.isArray(data.clients) || data.clients.length === 0) {
                container.textContent =
                    OC.L10N.translate("backupstatus", "No clients found.");
                return;
            }

            data.clients.forEach(function (client) {
                const row = document.createElement("div");
                row.className = "backupstatus-client-row";

                const text = document.createElement("span");
                text.textContent =
                    client.client_id + " | " +
                    client.source_id + " | " +
                    client.status;

                row.appendChild(text);

                async function runClientAction(action, button) {
                    button.disabled = true;

                    try {
                        const response = await fetch(
                            OC.generateUrl(
                                "/apps/backupstatus/settings/management-clients/{clientId}/{action}",
                                {
                                    clientId: client.client_id,
                                    action: action
                                }
                            ),
                            {
                                method: "POST",
                                headers: {
                                    "Accept": "application/json",
                                    "requesttoken": OC.requestToken
                                }
                            }
                        );

                        const raw = await response.text();
                        let result = null;

                        if (raw) {
                            try {
                                result = JSON.parse(raw);
                            } catch (e) {
                                throw new Error(
                                    "HTTP " + response.status + ": " + raw
                                );
                            }
                        }

                        if (!response.ok || !result || !result.success) {
                            throw new Error(
                                (result && result.error)
                                    ? result.error
                                    : "HTTP " + response.status + ": empty response"
                            );
                        }

                        await loadManagementClients();
                    } catch (error) {
                        button.disabled = false;
                        window.alert(
                            error.message ||
                            OC.L10N.translate("backupstatus", "Client action failed")
                        );
                    }
                }

                if (client.status === "active" || client.status === "suspended") {
                    const action = client.status === "active" ? "pause" : "resume";
                    const stateButton = document.createElement("button");

                    stateButton.type = "button";
                    stateButton.textContent =
                        action === "pause"
                            ? OC.L10N.translate("backupstatus", "Pause")
                            : OC.L10N.translate("backupstatus", "Resume");

                    stateButton.addEventListener("click", function () {
                        runClientAction(action, stateButton);
                    });

                    row.appendChild(stateButton);

                    const removeButton = document.createElement("button");
                    removeButton.type = "button";
                    removeButton.textContent =
                        OC.L10N.translate("backupstatus", "Remove");

                    removeButton.addEventListener("click", function () {
                        if (!window.confirm(
                            "Are you sure you want to remove " + client.client_id +
                            "? Backup data will be preserved."
                        )) {
                            return;
                        }

                        runClientAction("remove", removeButton);
                    });

                    row.appendChild(removeButton);
                }

                if (client.status === "terminated") {
                    const deleteButton = document.createElement("button");
                    deleteButton.type = "button";
                    deleteButton.textContent =
                        OC.L10N.translate("backupstatus", "Delete permanently");

                    deleteButton.addEventListener("click", function () {
                        if (!window.confirm(
                            "Are you sure you want to permanently delete " + client.client_id +
                            "? All backup data will also be deleted. This cannot be undone."
                        )) {
                            return;
                        }

                        runClientAction("delete", deleteButton);
                    });

                    row.appendChild(deleteButton);
                }

                container.appendChild(row);
            });
        } catch (error) {
            container.textContent =
                error.message ||
                OC.L10N.translate("backupstatus", "Unable to load clients.");
        }
    }

    document.addEventListener("DOMContentLoaded", loadManagementClients);


    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
