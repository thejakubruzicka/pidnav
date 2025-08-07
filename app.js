const { useEffect, useState } = React;

function App() {
  const [departures, setDepartures] = useState([]);
  const [stationId, setStationId] = useState("10533");
  const [inputStationId, setInputStationId] = useState("10533");
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchDepartures();
    const interval = setInterval(fetchDepartures, 30000);
    return () => clearInterval(interval);
  }, [stationId]);

  async function fetchDepartures() {
    try {
      setError(null);
      const response = await fetch("https://corsproxy.io/?https://tabule.dopravauk.cz/api/station/" + stationId);
      if (!response.ok) throw new Error("Network error");
      const data = await response.json();
      setDepartures(data.departures || []);
    } catch (err) {
      console.error(err);
      setError("Nepodařilo se načíst data pro stanici ID " + stationId);
      setDepartures([]);
    }
  }

  return (
    React.createElement("div", null,
      React.createElement("h1", { className: "text-2xl font-bold mb-4" }, "Odjezdy MHD"),
      React.createElement("div", { className: "mb-4 flex gap-2 items-center" },
        React.createElement("input", {
          type: "text",
          className: "bg-gray-800 text-white px-3 py-2 rounded w-48",
          value: inputStationId,
          onChange: (e) => setInputStationId(e.target.value),
          placeholder: "Zadej ID zastávky"
        }),
        React.createElement("button", {
          className: "bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded text-white",
          onClick: () => setStationId(inputStationId)
        }, "Načíst")
      ),
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
