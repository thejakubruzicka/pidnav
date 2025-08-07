
const { useEffect, useState } = React;

function App() {
  const [departures, setDepartures] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    async function fetchData() {
      try {
        const response = await fetch("https://corsproxy.io/?https://tabule.dopravauk.cz/api/station/10533");
        if (!response.ok) throw new Error("Network error");
        const data = await response.json();
        setDepartures(data.departures || []);
      } catch (err) {
        console.error(err);
        setError("Nepodařilo se načíst data.");
        setDepartures([
          { line: "133", destination: "Sídliště Malešice", minutes: 5 },
          { line: "177", destination: "Poliklinika Mazurská", minutes: 12 }
        ]);
      }
    }

    fetchData();
    const interval = setInterval(fetchData, 30000);
    return () => clearInterval(interval);
  }, []);

  return (
    React.createElement("div", null,
      React.createElement("h1", { className: "text-2xl font-bold mb-4" }, "Odjezdy MHD - Sídliště Čimice"),
      error && React.createElement("div", { className: "text-red-400 mb-4" }, error),
      React.createElement("ul", { className: "space-y-2" },
        departures.map((dep, i) =>
          React.createElement("li", { key: i, className: "bg-gray-800 p-3 rounded-md shadow-md flex justify-between items-center" },
            React.createElement("span", { className: "font-semibold text-lg" }, dep.line),
            React.createElement("span", null, dep.destination),
            React.createElement("span", { className: "text-green-400" }, dep.minutes + " min")
          )
        )
      )
    )
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(React.createElement(App));
