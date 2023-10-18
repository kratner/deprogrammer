import React, { useEffect, useState } from "react";
import { DateFormatters } from "../utils/DateFormatters";
import {
  CustomAccordion,
  CustomAccordionSummary,
  CustomAccordionDetails,
  CustomExpandMoreIcon,
} from "./CustomAccordion";

// Define the prop types for PostList
interface PostListProps {
  graphqlUrl: string;
  graphqlEndpoint: string;
  graphqlQuery: string;
  postListTitle: JSX.Element;
}

// Define the type for a single post
interface Post {
  id: string;
  title: string;
  content: string;
  categories: {
    edges: {
      node: {
        id: string;
        name: string;
      };
    }[];
  };
  date: string;
  excerpt: string;
  featuredImage: {
    node: {
      sourceUrl: string;
      altText: string;
    };
  };
  modified: string;
  uri: string;
}

const trimUrl = (url: string) => {
  return url.replace(/^https?:\/\/|\/$/g, "");
};

const PostList: React.FC<PostListProps> = ({
  graphqlUrl,
  graphqlQuery,
  graphqlEndpoint,
  postListTitle,
}) => {
  const trimmedUrl = trimUrl(graphqlUrl);
  const [posts, setPosts] = useState<Post[]>([]);
  const [loading, setLoading] = useState(true); // Track loading state

  useEffect(() => {
    // Fetch data from the GraphQL endpoint
    fetch(graphqlUrl + "/" + graphqlEndpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        query: graphqlQuery, // Use the passed GraphQL query
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        // Extract and set the posts from the GraphQL response
        setPosts(data.data.posts.edges.map((edge: any) => edge.node));
        setLoading(false);
      })
      .catch((error) => {
        console.error("Error fetching data:", error);
      });
  }, [graphqlUrl, graphqlEndpoint, graphqlQuery]); // Include graphqlUrl and graphqlQuery in the dependency array

  return (
    <div className="post-list">
      {loading ? ( // Show loading indicator if loading is true
        <div className="lds-facebook">
          <div></div>
          <div></div>
          <div></div>
        </div>
      ) : (
        <CustomAccordion>
          <CustomAccordionSummary expandIcon={<CustomExpandMoreIcon />}>
            <h2>{postListTitle}</h2>
          </CustomAccordionSummary>
          <CustomAccordionDetails>
            <ul>
              {posts.map((post) => (
                <li key={post.id}>
                  <CustomAccordion>
                    <CustomAccordionSummary
                      expandIcon={<CustomExpandMoreIcon />}
                    >
                      <h3>{post.title}</h3>
                      {/* <Typography variant="h4">{post.title}</Typography> */}
                    </CustomAccordionSummary>
                    <CustomAccordionDetails>
                      {/* Render post content using dangerouslySetInnerHTML */}

                      <a
                        href={graphqlUrl + post.uri}
                        rel="noreferrer"
                        target="_blank"
                      >
                        <img
                          src={post.featuredImage.node.sourceUrl}
                          alt={post.featuredImage.node.altText}
                          className="wp-post-featured-image"
                        />
                      </a>

                      <p dangerouslySetInnerHTML={{ __html: post.excerpt }}></p>
                      <p>
                        c: {DateFormatters.formatDate(post.date)} m:{" "}
                        {DateFormatters.formatDate(post.modified)}
                      </p>

                      <CustomAccordion>
                        <CustomAccordionSummary
                          expandIcon={<CustomExpandMoreIcon />}
                        >
                          <p className="source-ling">Read the article...</p>
                        </CustomAccordionSummary>
                        <CustomAccordionDetails>
                          <div
                            className="wp-post"
                            dangerouslySetInnerHTML={{ __html: post.content }}
                          ></div>
                        </CustomAccordionDetails>
                      </CustomAccordion>
                      <p className="source-link">
                        <a
                          href={graphqlUrl + post.uri}
                          rel="noreferrer"
                          target="_blank"
                        >
                          Read the article on {trimmedUrl}
                        </a>
                      </p>

                      {/* <h3>Category: {post.categories.edges[0]?.node?.name}</h3> */}
                    </CustomAccordionDetails>
                  </CustomAccordion>
                </li>
              ))}
            </ul>
          </CustomAccordionDetails>
        </CustomAccordion>
      )}
    </div>
  );
};

export default PostList;
