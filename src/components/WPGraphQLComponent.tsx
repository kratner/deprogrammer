import React, { useEffect, useState } from "react";

interface Data {
  // Define your data structure here
}

interface WPGraphQLProps {
  url: string;
  query: string;
}

const WPGraphQLComponent: React.FC<WPGraphQLProps> = ({ url, query }) => {
  const [data, setData] = useState<Data | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            query,
          }),
        });

        if (!response.ok) {
          throw new Error("Network response was not ok");
        }

        const result = await response.json();
        setData(result.data);
        setLoading(false);
      } catch (error: any) {
        setError(error.message || "An error occurred");
        setLoading(false);
      }
    };

    fetchData();
  }, [url, query]);

  if (loading) {
    return <div>Loading...</div>;
  }

  if (error) {
    return <div>Error: {error}</div>;
  }

  // Render your fetched data here
  // You can access the data in the `data` variable

  return (
    <div className="graphql-results">{/* Render your fetched data here */}</div>
  );
};

export default WPGraphQLComponent;
