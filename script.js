const workerUrl = "https://ruzovadoprava.ruzickajakub2006.workers.dev/";

async function loadDepartures() {
    const stationId = document.getElementById("stationId").value;
    const apiUrl = `${workerUrl}/?url=${encodeURIComponent(
        `https://tabule.dopravauk.cz/api/v1/departures?station=${stationId}&mode=basic&timemode=min&data=api`
    )}`;

    document.getElementById("results").innerHTML = "Načítám data...";

    try {
        const res = await fetch(apiUrl);
        const data = await res.json();

        let html = "<table><tr><th>Linka</th><th>Cíl</th><th>Odjezd</th><th>Dopravce</th></tr>";

        data.forEach(item => {
            html += `<tr>
                <td>${item.line || ""}</td>
                <td>${item.headsign || ""}</td>
                <td>${item.departure || ""}</td>
                <td>${item.carrier || ""}</td>
            </tr>`;
        });

        html += "</table>";
        document.getElementById("results").innerHTML = html;
    } catch (err) {
        document.getElementById("results").innerHTML = "Chyba při načítání dat.";
        console.error(err);
    }
}

document.getElementById("loadBtn").addEventListener("click", loadDepartures);
document.getElementById("refreshBtn").addEventListener("click", loadDepartures);
